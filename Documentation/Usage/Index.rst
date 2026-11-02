.. include:: ../Includes.txt

.. _usage:

=====
Usage
=====

.. _usage-scan:

Scan
====

Run `adaptive-video:scan` regularly, e.g. every 10 minutes as Scheduler task
**Execute console commands** or as cron job. It only queues jobs and returns
quickly. References saved in the backend do not wait for it, they are queued
on save.

::

	# List files that would be queued
	vendor/bin/typo3 adaptive-video:scan --dry-run

	# Queue and list them
	vendor/bin/typo3 adaptive-video:scan -v

.. _usage-worker:

Worker
======

The worker encodes one video at a time. Run **exactly one worker** per
environment; encodings are CPU heavy and long videos take hours.

Recommended is a process manager such as Supervisor or systemd. The TYPO3
core stops a worker after one hour (after the current job), the process
manager restarts it, so new code is loaded regularly.

.. code-block:: ini

   [program:adaptive_video_worker]
   command=/path/to/vendor/bin/typo3 messenger:consume adaptive_video
   autorestart=true
   numprocs=1
   ; Lets a running encoding finish on stop
   stopwaitsecs=10800

Without a process manager, cron with `flock` works as well. Calls fail on
the lock while a worker is running::

	* * * * * flock -n /tmp/adaptive_video.lock /path/to/vendor/bin/typo3 messenger:consume adaptive_video

Restart the worker after a deployment. An encoding aborted by a restart is
handed out again after `redeliverTimeout` and continues from the start.

`-v`, `-vv` and `-vvv` print the progress of the worker: `-vv` shows when a
video is started, encoded and ready.

.. _usage-status:

Status
======

::

	# Versions per status, running and failed jobs
	vendor/bin/typo3 adaptive-video:status

	# Additionally skipped versions
	vendor/bin/typo3 adaptive-video:status -v

	# Versions of a file, active version and playlist URL
	vendor/bin/typo3 adaptive-video:status <fileUid>

.. _usage-retry:

Retry and reprocess
===================

* **Retry a failed or skipped version**: delete its row from
  `tx_adaptivevideo_version`; the next scan queues the file again.
* **Reprocess everything** (e.g. after a broken profile): empty the artifact
  directory and the table `tx_adaptivevideo_version`, then run the scan. The
  originals are delivered until the new versions are ready.

Old versions and their files are not removed automatically.

.. _usage-deployment:

Environments and deployment
===========================

Database and artifact directory belong together: a `ready` version without
its files results in a broken player (the renderer does not check the
files). When copying a database between environments, copy the artifact
directory as well.

A typical rollout:

#. Deploy with `processingEnabled = 1` and `deliveryEnabled = 0` on
   production. Videos are encoded, the frontend still delivers the
   originals.
#. Copy database and artifact directory to a test environment with
   `deliveryEnabled = 1` and test there.
#. Enable delivery on production and flush the page cache.
