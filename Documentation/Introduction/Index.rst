.. include:: ../Includes.txt

.. _introduction:

============
Introduction
============

What does it do?
=================

This extension delivers FAL videos as adaptive
`HLS <https://en.wikipedia.org/wiki/HTTP_Live_Streaming>`__ streams. Videos
are encoded asynchronously with ffmpeg in a Symfony Messenger worker. Until a
stream is ready, and in browsers without JavaScript, the original file is
delivered.

* Works with all output via `<f:media>` / the `RendererRegistry`, e.g. the
  *Text & Media* content element.
* Native HLS where available (Safari, iOS), otherwise hls.js (light build,
  loaded only when needed).
* Poster images from `b13/poster-image <https://github.com/b13/poster-image>`__
  are supported.

How it works
=============

#. **Scan** (`adaptive-video:scan`, scheduled): finds supported FAL files
   with at least one active file reference that have no version for their
   current content (`sys_file.sha1`) and encoding profile. For each it
   creates a `pending` version in `tx_adaptivevideo_version` and queues a
   message.
#. **Worker** (`messenger:consume adaptive_video`): analyzes the source with
   ffprobe, encodes all renditions with ffmpeg into a working directory,
   validates the result, moves it to
   `<artifactDirectory>/<fileUid>/<versionUid>/` and marks the version
   `ready`. Then the page caches showing the file are flushed.
#. **Rendering**: the renderer delivers the newest `ready` version whose
   content fingerprint matches the current file. If the file was replaced,
   the original is delivered until the new version is ready.

Short videos (below `minimumDuration`) and videos above the configured
limits are marked `skipped` and keep using the original. Errors mark a
version `failed`; there is no automatic retry.

A file is only processed once it is referenced, not on upload. References
saved, copied or localized in the backend are queued right away by a
DataHandler hook; everything else (imports, migrations, direct database
changes) is picked up by the next scan.

Limitations
============

* Only output through `<f:media>` / the `RendererRegistry`. Templates with
  their own `<video>` markup are not covered.
* One active encoding profile for all sites.
* No automatic cleanup of old versions.

Screenshots
===========

This extension has no backend module. It works through the frontend
renderer, the CLI commands and the Messenger worker.
