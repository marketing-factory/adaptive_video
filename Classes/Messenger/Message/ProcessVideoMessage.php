<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Messenger\Message;

/**
 * Carries only the version uid; everything else is read from the database
 * when the message is handled.
 */
final readonly class ProcessVideoMessage
{
    public function __construct(
        public int $versionUid,
    ) {
    }
}
