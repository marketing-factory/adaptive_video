#
# Technical table without TCA, accessed via QueryBuilder only (ADR-016).
# The unique index guarantees that a file is processed only once per
# source content and encoding profile, even with parallel scans/workers.
#
CREATE TABLE tx_adaptivevideo_version (
	uid int(11) unsigned NOT NULL auto_increment,
	file_uid int(11) unsigned DEFAULT '0' NOT NULL,
	source_fingerprint varchar(40) DEFAULT '' NOT NULL,
	profile_fingerprint varchar(64) DEFAULT '' NOT NULL,
	profile_snapshot text,
	output_prefix varchar(255) DEFAULT '' NOT NULL,
	status varchar(16) DEFAULT 'pending' NOT NULL,
	processing_started_at int(11) unsigned DEFAULT '0' NOT NULL,
	error_message text,
	created_at int(11) unsigned DEFAULT '0' NOT NULL,
	completed_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY version_identity (file_uid, source_fingerprint, profile_fingerprint),
	KEY lookup (file_uid, status)
);
