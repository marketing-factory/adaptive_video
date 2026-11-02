# Acceptance checklist

Manual checks before enabling delivery in an environment. Automated tests cover
the logic; this list covers real ffmpeg output, browsers and operation.

Use `vendor/bin/typo3 adaptive-video:status <fileUid>` to look up the version
and playlist URL of a test video.

## Encoding

Run a scan and the worker with `-vv` and check each case with
`adaptive-video:status <fileUid>`.

- [ ] Landscape video (e.g. 1920×1080): `ready`, renditions up to the source size.
- [ ] Portrait video (e.g. 1080×1920): `ready`, player shows it upright and not stretched.
- [ ] Video with rotation metadata (e.g. recorded on a phone): orientation as in the original.
- [ ] Video without audio track: `ready`, plays without errors.
- [ ] 60 fps source: output limited to the profile frame rate (30 fps by default).
- [ ] Source below 360p: one rendition in the source size.
- [ ] Video below `minimumDuration`: `skipped`, original is delivered.
- [ ] Defective or non-video file with a supported extension: `failed` with a readable message.
- [ ] Working directory is empty after success and after failure.

## Delivery

On a page with a ready video, page cache flushed:

- [ ] Markup contains `data-adaptive-video-src` and the original as `<source>`.
- [ ] Chrome, Firefox, Edge: stream plays via hls.js (requests to `master.m3u8` and `.m4s` in the network tab).
- [ ] Safari macOS and iOS: stream plays natively.
- [ ] No CSP violations in the browser console.
- [ ] Quality adapts when throttling the network in the developer tools.
- [ ] With `preload` `none` or `metadata`, no segments are loaded before playing.
- [ ] Autoplay videos start muted, without loading the original first.
- [ ] Poster from file metadata is shown.
- [ ] Without JavaScript the original plays.
- [ ] If the playlist is not reachable (e.g. blocked in the developer tools), the original plays.
- [ ] Files without ready version, short videos and unsupported formats are delivered unchanged.

## Changes and operation

- [ ] New reference saved in the backend: the video is queued right away (`adaptive-video:status`), the page shows the stream after encoding without flushing the cache manually.
- [ ] Copying or translating a content element with an unprocessed video queues it right away.
- [ ] Reference created outside the backend (e.g. import): the next scan queues the video.
- [ ] Replacing the file: the page shows the original right away, the stream again after the next scan and encoding.
- [ ] `deliveryEnabled = 0`: all pages deliver the originals after flushing the page cache.
- [ ] Site set setting `adaptiveVideo.enabled = false`: the site delivers the originals.
- [ ] `processingEnabled = 0`: the scan queues nothing, existing streams are still delivered.
- [ ] Worker restart during an encoding: the version is processed again after `redeliverTimeout`.
- [ ] `adaptive-video:status` shows running, failed and (with `-v`) skipped versions.
- [ ] Deleting a failed version and scanning again queues the file again.
- [ ] Database and artifact directory copied to another environment: streams play there.
