.. include:: ../Includes.txt

.. _installation:

============
Installation
============

Install the extension via Composer::

	composer require mfd/adaptive-video
	vendor/bin/typo3 extension:setup

Requirements
============

* PHP 8.2+
* TYPO3 13.4 or 14
* `b13/poster-image <https://github.com/b13/poster-image>`__ 2.x
* `ffmpeg` and `ffprobe` on the host that runs the worker

Setup
=====

#. Install `ffmpeg` and `ffprobe` on the host that runs the worker.
#. Add the site set **Adaptive Video** (`mfd/adaptive-video`) to the sites
   that should deliver streams.
#. Enable processing in exactly the environments that encode (see
   :ref:`Configuration <configuration>`), schedule the scan and run the
   worker (see :ref:`Usage <usage>`).
#. Configure the web server and allow `blob:` in your Content Security
   Policy, see below.

.. _installation-webserver:

Web server and CDN
==================

The artifact directory is served as static files. Make sure that

* `.m3u8` is delivered as `application/vnd.apple.mpegurl` and `.m4s` as
  `video/iso.segment` (or `video/mp4`), e.g. for Apache:

  .. code-block:: apache

     AddType application/vnd.apple.mpegurl .m3u8
     AddType video/iso.segment .m4s

* the files may be cached for a long time: every version has its own
  directory, so the content behind a URL never changes.
* streams are served from the same host as the page. Otherwise the host has
  to be allowed in the CSP and send CORS headers.

.. _installation-csp:

Content Security Policy
=======================

The extension does not change the CSP. hls.js needs `blob:` for the media
source and its web worker:

.. code-block:: text

   media-src 'self' blob:;
   worker-src 'self' blob:;

Without it, streams do not play in browsers without native HLS (Chrome,
Firefox, Edge). Check the browser console for CSP violations when testing.
