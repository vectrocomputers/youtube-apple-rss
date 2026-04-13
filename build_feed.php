<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

function assert_absolute_ascii_url(string $name, string $value): void
{
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        throw new RuntimeException("{$name} is not a valid absolute URL: {$value}");
    }
    if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
        throw new RuntimeException("{$name} must be ASCII-only: {$value}");
    }
}

function extract_playlist_id(string $playlistUrlOrId): string
{
    if (preg_match('/(?:[?&]list=)([A-Za-z0-9_-]+)/', $playlistUrlOrId, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $playlistUrlOrId) === 1) {
        return $playlistUrlOrId;
    }
    throw new RuntimeException("Could not extract playlist ID from: {$playlistUrlOrId}");
}

function http_get(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Failed to initialize HTTP request for {$url}");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'PodcastRSSBuilder/1.0',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP GET failed for {$url}. Status={$status}. Error={$err}");
    }
    return $body;
}

function json_unescape_string(string $value): string
{
    $decoded = json_decode('"' . $value . '"', true);
    if (!is_string($decoded)) {
        throw new RuntimeException('Failed to decode escaped JSON string.');
    }
    return $decoded;
}

function html_meta_content(string $html, string $attrName, string $attrValue): string
{
    $pattern = '/<meta[^>]*' . preg_quote($attrName, '/') . '\s*=\s*"'
        . preg_quote($attrValue, '/') . '"[^>]*content\s*=\s*"([^"]*)"[^>]*>/i';
    if (preg_match($pattern, $html, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
}

function fetch_playlist_description(string $playlistId): string
{
    $url = "https://www.youtube.com/playlist?list={$playlistId}";
    $html = http_get($url);

    $jsonPattern = '/"playlistSidebarPrimaryInfoRenderer".*?"description"\s*:\s*\{"simpleText"\s*:\s*"((?:\\\\.|[^"\\\\])*)"\}/s';
    if (preg_match($jsonPattern, $html, $m) === 1) {
        $text = trim(json_unescape_string($m[1]));
        if ($text !== '') {
            return $text;
        }
    }

    $og = trim(html_meta_content($html, 'property', 'og:description'));
    if ($og !== '') {
        return $og;
    }

    throw new RuntimeException("Playlist description could not be extracted from YouTube playlist page for {$playlistId}");
}

function run_command(array $parts): void
{
    $escaped = array_map('escapeshellarg', $parts);
    $command = implode(' ', $escaped);

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptor, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to start command: {$command}");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        throw new RuntimeException("Command failed ({$exit}): {$command}\n{$stdout}\n{$stderr}");
    }
}

function format_rfc2822(string $iso8601): string
{
    $dt = new DateTimeImmutable($iso8601);
    return $dt->format('D, d M Y H:i:s O');
}

function ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create directory: {$dir}");
    }
}

function download_episode_media(string $ytDlpBin, string $videoId, string $mediaDir): array
{
    $watchUrl = "https://www.youtube.com/watch?v={$videoId}";
    run_command([
        $ytDlpBin,
        '--no-warnings',
        '--no-progress',
        '--no-overwrites',
        '--restrict-filenames',
        '--paths',
        $mediaDir,
        '-o',
        '%(id)s.%(ext)s',
        '-f',
        'best[ext=mp4][acodec!=none][vcodec!=none]/best[ext=mp4]',
        $watchUrl
    ]);

    $target = "{$mediaDir}/{$videoId}.mp4";
    if (!is_file($target)) {
        $matches = glob("{$mediaDir}/{$videoId}.*");
        if ($matches === false || count($matches) === 0) {
            throw new RuntimeException("Media file not found after download for video {$videoId}");
        }
        throw new RuntimeException("Video {$videoId} was not downloaded as MP4. Apple-compatible enclosure requires MP4/M4A/MP3.");
    }

    $size = filesize($target);
    if ($size === false || $size <= 0) {
        throw new RuntimeException("Invalid media file size for {$target}");
    }

    return [
        'filename' => basename($target),
        'length' => (string)$size,
        'type' => 'video/mp4',
    ];
}

function add_text(DOMDocument $doc, DOMElement $parent, string $name, string $value): DOMElement
{
    $el = $doc->createElement($name);
    $el->appendChild($doc->createTextNode($value));
    $parent->appendChild($el);
    return $el;
}

$playlistInput = 'YOUTUBE_PLAYLIST_URL';
$playlistId = extract_playlist_id($playlistInput);

$feedUrl = 'https://YOUR_DOMAIN/applerss/public/podcast.xml';
$mediaBaseUrl = 'https:/YOUR_DOMAIN/applerss/public/media';
$artworkUrl = 'https://YOUR_DOMAIN/applerss/artwork.jpg';
$ownerEmail = 'you@yourdomain.com';
$ownerName = 'ARTIST_NAME';
$language = 'en-US';
$explicit = 'false';
$category = 'PODCAST_CATEGORY';
$subcategory = '';
$podcastLink = "https://www.youtube.com/playlist?list={$playlistId}";
$outputDir = __DIR__ . '/public';
$mediaDirName = 'media';
$ytDlpBin = '/path/to/yt-dlp';
$maxEpisodes = 0;

assert_absolute_ascii_url('PODCAST_FEED_URL', $feedUrl);
assert_absolute_ascii_url('PODCAST_MEDIA_BASE_URL', $mediaBaseUrl);
assert_absolute_ascii_url('PODCAST_ARTWORK_URL', $artworkUrl);
assert_absolute_ascii_url('PODCAST_LINK', $podcastLink);

if (!is_file($ytDlpBin)) {
    throw new RuntimeException("yt-dlp binary not found at {$ytDlpBin}");
}
if (!is_executable($ytDlpBin)) {
    throw new RuntimeException("yt-dlp binary is not executable: {$ytDlpBin}");
}

if (!is_dir($outputDir)) {
    ensure_dir($outputDir);
}
$mediaDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $mediaDirName;
ensure_dir($mediaDir);

$playlistFeedUrl = "https://www.youtube.com/feeds/videos.xml?playlist_id={$playlistId}";
$xmlBody = http_get($playlistFeedUrl);

$src = new DOMDocument();
$src->preserveWhiteSpace = false;
if (!$src->loadXML($xmlBody)) {
    throw new RuntimeException('Failed to parse YouTube playlist feed XML.');
}
$xp = new DOMXPath($src);
$xp->registerNamespace('a', 'http://www.w3.org/2005/Atom');
$xp->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
$xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');

$playlistTitleNode = $xp->query('/a:feed/a:title')->item(0);
$playlistAuthorNode = $xp->query('/a:feed/a:author/a:name')->item(0);
$playlistTitle = $playlistTitleNode ? trim($playlistTitleNode->textContent) : '';
$playlistAuthor = $playlistAuthorNode ? trim($playlistAuthorNode->textContent) : '';
$playlistDescription = fetch_playlist_description($playlistId);

$podcastTitle = $playlistTitle;
if ($podcastTitle === '') {
    throw new RuntimeException('Podcast title could not be resolved from YouTube playlist feed.');
}

if ($ownerName === '') {
    $ownerName = $playlistAuthor;
}
if ($ownerName === '') {
    throw new RuntimeException('Owner name is empty.');
}

$episodeNodes = $xp->query('/a:feed/a:entry');
if ($episodeNodes === false || $episodeNodes->length === 0) {
    throw new RuntimeException('No episodes found in playlist feed.');
}

$episodes = [];
foreach ($episodeNodes as $entry) {
    $videoIdNode = $xp->query('yt:videoId', $entry)->item(0);
    $titleNode = $xp->query('a:title', $entry)->item(0);
    $publishedNode = $xp->query('a:published', $entry)->item(0);
    $descriptionNode = $xp->query('media:group/media:description', $entry)->item(0);
    $linkNode = $xp->query('a:link[@rel="alternate"]', $entry)->item(0);

    if (!$videoIdNode || !$titleNode || !$publishedNode || !$linkNode) {
        continue;
    }

    $videoId = trim($videoIdNode->textContent);
    $title = trim($titleNode->textContent);
    $publishedIso = trim($publishedNode->textContent);
    $description = $descriptionNode ? trim($descriptionNode->textContent) : $title;
    $watchUrl = $linkNode instanceof DOMElement ? (string)$linkNode->getAttribute('href') : '';

    if ($videoId === '' || $watchUrl === '') {
        continue;
    }

    $episodes[] = [
        'video_id' => $videoId,
        'title' => $title,
        'description' => $description,
        'published_iso' => $publishedIso,
        'published_rfc2822' => format_rfc2822($publishedIso),
        'watch_url' => $watchUrl,
    ];
}

usort($episodes, static fn(array $a, array $b): int => strcmp($b['published_iso'], $a['published_iso']));
if ($maxEpisodes > 0) {
    $episodes = array_slice($episodes, 0, $maxEpisodes);
}
if (count($episodes) === 0) {
    throw new RuntimeException('No valid episodes to process.');
}

foreach ($episodes as $idx => $ep) {
    fwrite(STDOUT, "Downloading {$ep['video_id']}\n");
    $media = download_episode_media($ytDlpBin, $ep['video_id'], $mediaDir);
    $episodes[$idx]['enclosure_filename'] = $media['filename'];
    $episodes[$idx]['enclosure_length'] = $media['length'];
    $episodes[$idx]['enclosure_type'] = $media['type'];
}

$podcastDescription = trim($playlistDescription);
if ($podcastDescription === '') {
    throw new RuntimeException('Playlist description is empty.');
}

$rss = new DOMDocument('1.0', 'UTF-8');
$rss->formatOutput = true;

$rssNode = $rss->createElement('rss');
$rssNode->setAttribute('version', '2.0');
$rssNode->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
$rssNode->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
$rssNode->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
$rss->appendChild($rssNode);

$channel = $rss->createElement('channel');
$rssNode->appendChild($channel);

add_text($rss, $channel, 'title', $podcastTitle);
add_text($rss, $channel, 'link', $podcastLink);
add_text($rss, $channel, 'language', $language);
add_text($rss, $channel, 'description', $podcastDescription);
add_text($rss, $channel, 'itunes:author', $playlistAuthor !== '' ? $playlistAuthor : $ownerName);
add_text($rss, $channel, 'itunes:summary', $podcastDescription);
add_text($rss, $channel, 'itunes:type', 'episodic');
add_text($rss, $channel, 'itunes:explicit', $explicit);
add_text($rss, $channel, 'lastBuildDate', gmdate('D, d M Y H:i:s') . ' GMT');

$atomLink = $rss->createElement('atom:link');
$atomLink->setAttribute('href', $feedUrl);
$atomLink->setAttribute('rel', 'self');
$atomLink->setAttribute('type', 'application/rss+xml');
$channel->appendChild($atomLink);

$owner = $rss->createElement('itunes:owner');
add_text($rss, $owner, 'itunes:name', $ownerName);
add_text($rss, $owner, 'itunes:email', $ownerEmail);
$channel->appendChild($owner);

$image = $rss->createElement('itunes:image');
$image->setAttribute('href', $artworkUrl);
$channel->appendChild($image);

$cat = $rss->createElement('itunes:category');
$cat->setAttribute('text', $category);
if ($subcategory !== '') {
    $sub = $rss->createElement('itunes:category');
    $sub->setAttribute('text', $subcategory);
    $cat->appendChild($sub);
}
$channel->appendChild($cat);

foreach ($episodes as $ep) {
    $item = $rss->createElement('item');
    $channel->appendChild($item);

    $enclosureUrl = $mediaBaseUrl . '/' . rawurlencode($ep['enclosure_filename']);
    assert_absolute_ascii_url('enclosure url', $enclosureUrl);

    add_text($rss, $item, 'title', $ep['title']);
    add_text($rss, $item, 'description', $ep['description']);
    add_text($rss, $item, 'content:encoded', $ep['description']);
    add_text($rss, $item, 'pubDate', $ep['published_rfc2822']);

    $guid = add_text($rss, $item, 'guid', 'yt:' . $ep['video_id']);
    $guid->setAttribute('isPermaLink', 'false');

    add_text($rss, $item, 'link', $ep['watch_url']);
    add_text($rss, $item, 'itunes:author', $playlistAuthor !== '' ? $playlistAuthor : $ownerName);
    add_text($rss, $item, 'itunes:summary', $ep['description']);
    add_text($rss, $item, 'itunes:explicit', $explicit);
    add_text($rss, $item, 'itunes:episodeType', 'full');

    $enc = $rss->createElement('enclosure');
    $enc->setAttribute('url', $enclosureUrl);
    $enc->setAttribute('length', $ep['enclosure_length']);
    $enc->setAttribute('type', $ep['enclosure_type']);
    $item->appendChild($enc);
}

$targetXml = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'podcast.xml';
if ($rss->save($targetXml) === false) {
    throw new RuntimeException("Failed to write {$targetXml}");
}

fwrite(STDOUT, "Feed written: {$targetXml}\n");
fwrite(STDOUT, "Episodes: " . count($episodes) . "\n");
fwrite(STDOUT, "Media: {$mediaDir}\n");
