<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Domain\Model;

enum VersionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    /** Not processed on purpose, e.g. below the minimum duration */
    case Skipped = 'skipped';
}
