<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if ($argc < 2) {
    fwrite(STDERR, "Usage: php validate_feed.php https://YOUR_DOMAIN/podcast/podcast.xml\n");
    exit(1);
}

$feedUrl = trim($argv[1]);
if (!filter_var($feedUrl, FILTER_VALIDATE_URL)) {
    throw new RuntimeException("Invalid feed URL: {$feedUrl}");
}

function curl_request(string $url, bool $head = false): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Failed to initialize cURL for {$url}");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => 'PodcastRSSValidator/1.0',
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => $head,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("HTTP request failed for {$url}. {$err}");
    }
    $headersRaw = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $headers = [];
    foreach (explode("\n", str_replace("\r", '', $headersRaw)) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
    ];
}

function require_xpath_nonempty(DOMXPath $xp, string $path, DOMNode $context = null): DOMNode
{
    $result = $context ? $xp->query($path, $context) : $xp->query($path);
    if ($result === false || $result->length === 0) {
        throw new RuntimeException("Missing required node: {$path}");
    }
    $node = $result->item(0);
    if ($node === null || trim($node->textContent) === '') {
        throw new RuntimeException("Empty required node: {$path}");
    }
    return $node;
}

$feedResponse = curl_request($feedUrl, false);
if ($feedResponse['status'] < 200 || $feedResponse['status'] >= 300) {
    throw new RuntimeException("Feed HTTP status is not 2xx: {$feedResponse['status']}");
}

$xml = new DOMDocument();
if (!$xml->loadXML($feedResponse['body'])) {
    throw new RuntimeException('Feed is not valid XML.');
}

$xp = new DOMXPath($xml);
$xp->registerNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
$xp->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

$channel = require_xpath_nonempty($xp, '/rss/channel');
require_xpath_nonempty($xp, 'title', $channel);
require_xpath_nonempty($xp, 'description', $channel);
require_xpath_nonempty($xp, 'language', $channel);
require_xpath_nonempty($xp, 'itunes:explicit', $channel);
require_xpath_nonempty($xp, 'itunes:owner/itunes:name', $channel);
require_xpath_nonempty($xp, 'itunes:owner/itunes:email', $channel);

$imageNodes = $xp->query('itunes:image', $channel);
if ($imageNodes === false || $imageNodes->length === 0) {
    throw new RuntimeException('Missing itunes:image.');
}
$imageNode = $imageNodes->item(0);
if (!$imageNode instanceof DOMElement) {
    throw new RuntimeException('Invalid itunes:image.');
}
$imageHref = trim($imageNode->getAttribute('href'));
if ($imageHref === '' || !filter_var($imageHref, FILTER_VALIDATE_URL)) {
    throw new RuntimeException("itunes:image href is invalid: {$imageHref}");
}

$catNodes = $xp->query('itunes:category', $channel);
if ($catNodes === false || $catNodes->length === 0) {
    throw new RuntimeException('Missing itunes:category.');
}

$items = $xp->query('item', $channel);
if ($items === false || $items->length === 0) {
    throw new RuntimeException('No episodes found.');
}

$seenEnclosures = [];
for ($i = 0; $i < $items->length; $i++) {
    $item = $items->item($i);
    if (!$item instanceof DOMNode) {
        continue;
    }
    require_xpath_nonempty($xp, 'title', $item);
    require_xpath_nonempty($xp, 'guid', $item);
    require_xpath_nonempty($xp, 'pubDate', $item);

    $encNodes = $xp->query('enclosure', $item);
    if ($encNodes === false || $encNodes->length === 0) {
        throw new RuntimeException("Episode {$i} missing enclosure.");
    }
    $enc = $encNodes->item(0);
    if (!$enc instanceof DOMElement) {
        throw new RuntimeException("Episode {$i} enclosure is invalid.");
    }
    $encUrl = trim($enc->getAttribute('url'));
    $encLength = trim($enc->getAttribute('length'));
    $encType = trim($enc->getAttribute('type'));

    if ($encUrl === '' || $encLength === '' || $encType === '') {
        throw new RuntimeException("Episode {$i} enclosure must include url, length, type.");
    }
    if (!filter_var($encUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException("Episode {$i} enclosure URL is invalid: {$encUrl}");
    }
    if (!ctype_digit($encLength)) {
        throw new RuntimeException("Episode {$i} enclosure length is invalid: {$encLength}");
    }
    if (isset($seenEnclosures[$encUrl])) {
        throw new RuntimeException("Duplicate enclosure URL: {$encUrl}");
    }
    $seenEnclosures[$encUrl] = true;

    $head = curl_request($encUrl, true);
    if ($head['status'] < 200 || $head['status'] >= 400) {
        throw new RuntimeException("Episode {$i} enclosure HEAD request failed ({$head['status']}): {$encUrl}");
    }
    $acceptRanges = strtolower($head['headers']['accept-ranges'] ?? '');
    if ($acceptRanges !== 'bytes') {
        throw new RuntimeException("Episode {$i} enclosure missing Accept-Ranges: bytes ({$encUrl})");
    }
}

fwrite(STDOUT, "Validation passed.\n");
fwrite(STDOUT, "Feed: {$feedUrl}\n");
fwrite(STDOUT, "Episodes: {$items->length}\n");
