# youtube-apple-rss
Turn a YouTube playlist into RSS Feed for Apple Podcast

by Vectro - https://x.com/vectro | https://vectro.chat

I have a lot of other things to work on, so this was vibe-coded using GPT-5.3-Codex. Some parts are a little redundant, but it works. Please feel free to make a PR to cleant it up and help everyone who will use it. I needed to get this out quickly and move on to other work. You know how it is.

# Production RSS Feed Builder (PHP)

This implementation is server-native PHP and designed for web-server deployment.

It does all required production work:
- Pulls your YouTube playlist feed
- Downloads each episode to local MP4 media files with `yt-dlp` (requires fair amount of bandwidth and CPU)
- Generates `podcast.xml` with Apple-required RSS fields and enclosure metadata
- Validates the live feed and enclosure endpoints before Apple submission

## 1) Server Requirements

- PHP with `curl`, `dom`, and `libxml`
- `yt-dlp` installed and executable
- Web server that serves static files and supports:
  - `HEAD` requests
  - `Accept-Ranges: bytes` for media files

## 2) Files

- `build_feed.php` generates `podcast.xml` and media files
- `validate_feed.php` validates a deployed feed URL

## 3) Variables to Set (Required) (lines 182 - 198)

- `$playlistInput` = YouTube playlist URL
- `$feedUrl` = The URL of your YouTube
- `$mediaBaseUrl` = URL where the RSS feed will go
- `$artworkUrl` = URL of your podcast artword (1400x1400 up to 3000x3000 pixel size JPG)
- `$ownerEmail` = Your email address
- `$ownerName` = Artist name for podcast
- `$language` = Language of your podcast (default = English)
- `$category` = Category of your podcast
- `$ytDlpBin` = Location of yt-dlp

## 4) Build Feed

Run in your server directory:

```bash
php build_feed.php
```

Output:
- `public/podcast.xml`
- `public/media/<videoId>.mp4`

## 5) Deploy

Serve the `public/` directory at your podcast URL path so that:
- `PODCAST_FEED_URL` points to `podcast.xml`
- `PODCAST_MEDIA_BASE_URL` points to `public/media`

## 6) Validate Live Feed

Set your domain name on line 8 in validate_feed.php

```bash
php validate_feed.php "$PODCAST_FEED_URL"
```

If validation passes, submit `PODCAST_FEED_URL` in Apple Podcasts Connect.

## 7) Cron (Automated Updates)

Example 30-minute refresh:

```cron
*/30 * * * * cd /var/www/podcast && /usr/bin/php build_feed.php >> /var/log/podcast-rss.log 2>&1
```
