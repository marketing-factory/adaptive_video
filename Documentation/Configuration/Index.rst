.. include:: ../Includes.txt

.. _configuration:

=============
Configuration
=============

.. _configuration-extension:

Extension configuration
=======================

Set in **Admin Tools > Settings > Extension Configuration** or in
`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['adaptive_video']`. Encoding is
configured globally for the whole installation.

.. container:: table-row

	Property
		processingEnabled

	Default
		`0`

	Description
		Create versions and dispatch encoding jobs in this environment.
		Existing ready versions are delivered regardless.

.. container:: table-row

	Property
		deliveryEnabled

	Default
		`1`

	Description
		Deliver ready versions as HLS. Disable to encode first and switch
		the frontend later. Flush the page cache after enabling.

.. container:: table-row

	Property
		defaultProfile

	Default
		`default`

	Description
		Identifier of the active encoding profile, see :ref:`configuration-
		profile`.

.. container:: table-row

	Property
		supportedFileExtensions

	Default
		`mp4,mov,m4v,webm`

	Description
		Video file extensions that are processed.

.. container:: table-row

	Property
		minimumDuration

	Default
		`10`

	Description
		Shorter videos (seconds) are skipped.

.. container:: table-row

	Property
		ffmpegPath

	Default
		`ffmpeg`

	Description
		ffmpeg binary, absolute or looked up in `PATH`.

.. container:: table-row

	Property
		ffprobePath

	Default
		`ffprobe`

	Description
		ffprobe binary, absolute or looked up in `PATH`.

.. container:: table-row

	Property
		commandPrefix

	Default
		*empty*

	Description
		Arguments placed before the binaries.

.. container:: table-row

	Property
		ffmpegOptions

	Default
		*empty*

	Description
		Additional output options for all encoders. All options except
		`-threads` are part of the profile fingerprint: changing them re-
		encodes all videos.

.. container:: table-row

	Property
		maxRuntime

	Default
		`10800`

	Description
		ffmpeg is aborted after this time (seconds) and the version marked
		`failed`. A version of a crashed worker can be taken over after this
		time.

.. container:: table-row

	Property
		redeliverTimeout

	Default
		`14400`

	Description
		A job not acknowledged within this time (seconds) is handed out
		again (crashed worker). Must be higher than `maxRuntime`.

.. container:: table-row

	Property
		maxSourceFileSize

	Default
		`0`

	Description
		Larger files (MB) are skipped. `0` disables the limit.

.. container:: table-row

	Property
		maxSourceDuration

	Default
		`0`

	Description
		Longer videos (seconds) are skipped. `0` disables the limit.

.. container:: table-row

	Property
		artifactDirectory

	Default
		`_videos`

	Description
		Directory for the streams, relative to the public web root.

.. container:: table-row

	Property
		publicUrlPrefix

	Default
		`/_videos/`

	Description
		URL path of the artifact directory.

.. container:: table-row

	Property
		temporaryDirectory

	Default
		`var/transient/adaptive_video`

	Description
		Working directory; should be on the same filesystem as the artifact
		directory.

.. container:: table-row

	Property
		rendererPriority

	Default
		`20`

	Description
		Must be higher than the renderers it replaces (`b13/poster-image`:
		10, Core: 1).

.. _configuration-profile:

Encoding profile
================

The default profile encodes 1080p (5000 kbit/s), 720p (2800), 480p (1400)
and 360p (800) with H.264, AAC 128 kbit/s, at most 30 fps and 4 second fMP4
segments. The height refers to the shorter side, so portrait videos get the
same quality steps. Renditions larger than the source are left out; a source
smaller than all of them is encoded once in its own size.

Profiles can be added or overridden:

.. code-block:: php

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

Every change of the active profile creates new versions on the next scan.
The previous versions stay active until the new ones are ready.

.. _configuration-site:

Site settings
=============

The site set **Adaptive Video** (`mfd/adaptive-video`) provides these
settings per site:

.. container:: table-row

	Setting
		adaptiveVideo.enabled

	Default
		`true`

	Description
		Deliver streams on this site.

.. container:: table-row

	Setting
		adaptiveVideo.preload

	Default
		`metadata`

	Description
		Used if the template sets no `preload`. With `none` and `metadata`,
		segments are loaded on play only.

.. container:: table-row

	Setting
		adaptiveVideo.capLevelToPlayerSize

	Default
		`true`

	Description
		hls.js does not load renditions larger than the player. Native HLS
		(Safari) decides on its own.

.. _configuration-logging:

Logging
=======

Entries use the channel `Mfd.AdaptiveVideo.*` with file and version uid as
context. By default TYPO3 only writes warnings and errors to
`var/log/typo3_*.log`. To log every finished video to its own file:

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']['Mfd']['AdaptiveVideo']['writerConfiguration'] = [
       \Psr\Log\LogLevel::INFO => [
           \TYPO3\CMS\Core\Log\Writer\FileWriter::class => ['logFileInfix' => 'adaptive_video'],
       ],
   ];
