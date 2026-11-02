<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Domain\Model;

use Mfd\AdaptiveVideo\Processing\ProfileSnapshot;

/**
 * One encoding of a file for a given source content and profile. A version is
 * never switched off: it is active as long as it is the newest ready version
 * whose source fingerprint matches the current sys_file.sha1 (ADR-005).
 */
final readonly class Version
{
    public function __construct(
        public int $uid,
        public int $fileUid,
        public string $sourceFingerprint,
        public string $profileFingerprint,
        public ProfileSnapshot $profileSnapshot,
        public string $outputPrefix,
        public VersionStatus $status,
        public int $processingStartedAt,
        public string $errorMessage,
        public int $createdAt,
        public int $completedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['uid'],
            (int)$row['file_uid'],
            (string)$row['source_fingerprint'],
            (string)$row['profile_fingerprint'],
            ProfileSnapshot::fromJson((string)$row['profile_snapshot']),
            (string)$row['output_prefix'],
            VersionStatus::from((string)$row['status']),
            (int)$row['processing_started_at'],
            (string)($row['error_message'] ?? ''),
            (int)$row['created_at'],
            (int)$row['completed_at'],
        );
    }
}
