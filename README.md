# Adaptive Video

Delivers FAL videos as adaptive [HLS](https://en.wikipedia.org/wiki/HTTP_Live_Streaming) streams. Videos are encoded asynchronously
with ffmpeg in a Symfony Messenger worker; until a stream is ready, and in
browsers without JavaScript, the original file is delivered.

- TYPO3 13.4 and 14, PHP 8.2+
- Works with all output via `<f:media>` / the `RendererRegistry` (e.g. the
  Text & Media content element). Templates with their own `<video>` markup are
  not covered yet.
- Native HLS where available (Safari, iOS), otherwise hls.js (light build,
  loaded only when needed).
- Poster images from `b13/poster-image` are supported.

## How it works

1. **Scan** (`adaptive-video:scan`, scheduled): finds supported FAL files with at
   least one active file reference that have no version for their current
   content (`sys_file.sha1`) and encoding profile. For each it creates a
   `pending` version in `tx_adaptivevideo_version` and queues a message.
2. **Worker** (`messenger:consume adaptive_video`): analyzes the source with
   ffprobe, encodes all renditions with ffmpeg into a working directory,
   validates the result, moves it to `<artifactDirectory>/<fileUid>/<versionUid>/`
   and marks the version `ready`. Then the page caches showing the file are
   flushed.
3. **Rendering**: the renderer delivers the newest `ready` version whose content
   fingerprint matches the current file. If the file was replaced, the original
   is delivered until the new version is ready.

Short videos (below `minimumDuration`) and videos above the configured limits
are marked `skipped` and keep using the original. Errors mark a version
`failed`; there is no automatic retry.

A file is only processed once it is referenced, not on upload. References
saved, copied or localized in the backend are queued right away by a
DataHandler hook; everything else (imports, migrations, direct database
changes) is picked up by the next scan.

## Installation

```bash
composer require mfd/adaptive-video
vendor/bin/typo3 extension:setup
```

1. Install `ffmpeg` and `ffprobe` on the host that runs the worker.
2. Add the site set **Adaptive Video** (`mfd/adaptive-video`) to the sites that
   should deliver streams.
3. Enable processing in exactly the environments that encode (see
   [Configuration](#configuration)), schedule the scan and run the worker (see
   [Operation](#operation)).
4. Allow `blob:` in your Content Security Policy (see
   [Content Security Policy](#content-security-policy)).

## Configuration

### Extension configuration

Set in *Admin Tools > Settings > Extension Configuration* or in
`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['adaptive_video']`.

| Setting                     | Default                        | Description                                                                                                                                         |
|-----------------------------|--------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| `processingEnabled`         | `0`                            | Create versions and dispatch encoding jobs in this environment. Existing ready versions are delivered regardless.                                   |
| `deliveryEnabled`           | `1`                            | Deliver ready versions as HLS. Disable to encode first and switch the frontend later. Flush the page cache after enabling.                          |
| `defaultProfile`            | `default`                      | Identifier of the active encoding profile.                                                                                                          |
| `supportedFileExtensions`   | `mp4,mov,m4v,webm`             | Video file extensions that are processed.                                                                                                           |
| `minimumDuration`           | `10`                           | Shorter videos (seconds) are skipped.                                                                                                               |
| `ffmpegPath`, `ffprobePath` | `ffmpeg`, `ffprobe`            | Binaries, absolute or looked up in `PATH`.                                                                                                          |
| `commandPrefix`             |                                | Arguments placed before the binaries.                                                                                                               |
| `ffmpegOptions`             |                                | Additional output options for all encoders. All options except `-threads` are part of the profile fingerprint: changing them re-encodes all videos. |
| `maxRuntime`                | `10800`                        | ffmpeg is aborted after this time (seconds) and the version marked `failed`. A version of a crashed worker can be taken over after this time.       |
| `redeliverTimeout`          | `14400`                        | A job not acknowledged within this time is handed out again (crashed worker). Must be higher than `maxRuntime`.                                     |
| `maxSourceFileSize`         | `0`                            | Larger files (MB) are skipped. `0` disables the limit.                                                                                              |
| `maxSourceDuration`         | `0`                            | Longer videos (seconds) are skipped. `0` disables the limit.                                                                                        |
| `artifactDirectory`         | `_videos`                      | Directory for the streams, relative to the public web root.                                                                                         |
| `publicUrlPrefix`           | `/_videos/`                    | URL path of the artifact directory.                                                                                                                 |
| `temporaryDirectory`        | `var/transient/adaptive_video` | Working directory; should be on the same filesystem as the artifact directory.                                                                      |
| `rendererPriority`          | `20`                           | Must be higher than the renderers it replaces (`b13/poster-image`: 10, Core: 1).                                                                    |

### Encoding profile

The default profile encodes 1080p (5000 kbit/s), 720p (2800), 480p (1400) and
360p (800) with H.264, AAC 128 kbit/s, at most 30 fps and 4 second fMP4
segments. The height refers to the shorter side, so portrait videos get the
same quality steps. Renditions larger than the source are left out; a source
smaller than all of them is encoded once in its own size.

Profiles can be added or overridden:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adaptive_video']['profiles']['default'] = [
    'renditions' => [
        ['height' => 1080, 'videoBitrate' => 4500],
        ['height' => 720, 'videoBitrate' => 2500],
        ['height' => 360, 'videoBitrate' => 800],
    ],
    'audioBitrate' => 128,
    'audioSampleRate' => 48000,
    'maxFrameRate' => 30,
    'segmentDuration' => 4,
];
```

Every change of the active profile creates new versions on the next scan. The
previous versions stay active until the new ones are ready.

### Site settings

| Setting | Default | Description |
| --- | --- | --- |
| `adaptiveVideo.enabled` | `true` | Deliver streams on this site. |
| `adaptiveVideo.preload` | `metadata` | Used if the template sets no `preload`. With `none` and `metadata`, segments are loaded on play only. |
| `adaptiveVideo.capLevelToPlayerSize` | `true` | hls.js does not load renditions larger than the player. |

## Operation

### Scan

Run `adaptive-video:scan` regularly, e.g. every 10 minutes as scheduler task
*Execute console commands* or as cron job. It only queues jobs and returns
quickly. References saved in the backend do not wait for it, they are queued
on save.

```bash
vendor/bin/typo3 adaptive-video:scan --dry-run   # list files that would be queued
vendor/bin/typo3 adaptive-video:scan -v          # queue and list them
```

### Worker

The worker encodes one video at a time. Run **exactly one worker** per
environment; encodings are CPU heavy and long videos take hours.

Recommended is a process manager such as Supervisor or systemd. The TYPO3 core
stops a worker after one hour (after the current job), the process manager
restarts it, so new code is loaded regularly.

```ini
[program:adaptive_video_worker]
command=/path/to/vendor/bin/typo3 messenger:consume adaptive_video
autorestart=true
numprocs=1
; Lets a running encoding finish on stop
stopwaitsecs=10800
```

Without a process manager, cron with `flock` works as well. Calls fail on the
lock while a worker is running:

```cron
* * * * * flock -n /tmp/adaptive_video.lock /path/to/vendor/bin/typo3 messenger:consume adaptive_video
```

Restart the worker after a deployment. An encoding aborted by a restart is
handed out again after `redeliverTimeout` and continues from the start.

`-v`, `-vv` and `-vvv` print the progress of the worker: `-vv` shows when a
video is started, encoded and ready.

### Status

```bash
vendor/bin/typo3 adaptive-video:status          # versions per status, running and failed jobs
vendor/bin/typo3 adaptive-video:status -v       # additionally skipped versions
vendor/bin/typo3 adaptive-video:status <fileUid> # versions of a file, active version and playlist URL
```

### Retry and reprocess

- **Retry a failed or skipped version**: delete its row from
  `tx_adaptivevideo_version`; the next scan queues the file again.
- **Reprocess everything** (e.g. after a broken profile): empty the artifact
  directory and the table `tx_adaptivevideo_version`, then run the scan. The
  originals are delivered until the new versions are ready.

Old versions and their files are not removed automatically.

### Logging

Entries use the channel `Mfd.AdaptiveVideo.*` with file and version uid as
context. By default TYPO3 only writes warnings and errors to
`var/log/typo3_*.log`. To log every finished video to its own file:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Mfd']['AdaptiveVideo']['writerConfiguration'] = [
    \Psr\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => ['logFileInfix' => 'adaptive_video'],
    ],
];
```

### Environments and deployment

Database and artifact directory belong together: a `ready` version without its
files results in a broken player (the renderer does not check the files). When
copying a database between environments, copy the artifact directory as well.

A typical rollout:

1. Deploy with `processingEnabled = 1` and `deliveryEnabled = 0` on production.
   Videos are encoded, the frontend still delivers the originals.
2. Copy database and artifact directory to a test environment with
   `deliveryEnabled = 1` and test there.
3. Enable delivery on production and flush the page cache.

## Web server and CDN

The artifact directory is served as static files. Make sure that

- `.m3u8` is delivered as `application/vnd.apple.mpegurl` and `.m4s` as
  `video/iso.segment` (or `video/mp4`), e.g. for Apache:

  ```apache
  AddType application/vnd.apple.mpegurl .m3u8
  AddType video/iso.segment .m4s
  ```

- the files may be cached for a long time: every version has its own
  directory, so the content behind a URL never changes.
- streams are served from the same host as the page. Otherwise the host has to
  be allowed in the CSP and send CORS headers.

## Content Security Policy

The extension does not change the CSP. hls.js needs `blob:` for the media
source and its web worker:

```text
media-src 'self' blob:;
worker-src 'self' blob:;
```

Without it, streams do not play in browsers without native HLS (Chrome,
Firefox, Edge). Check the browser console for CSP violations when testing.

## Limitations

- Only output through `<f:media>` / the `RendererRegistry`.
- One active encoding profile for all sites.
- No automatic cleanup of old versions.

## Acceptance checklist

See [Documentation/AcceptanceChecklist/Index.rst](Documentation/AcceptanceChecklist/Index.rst).
