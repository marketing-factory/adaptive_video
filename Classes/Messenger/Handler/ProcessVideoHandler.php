<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Messenger\Handler;

use Mfd\AdaptiveVideo\Messenger\Message\ProcessVideoMessage;
use Mfd\AdaptiveVideo\Processing\VideoProcessingService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessVideoHandler
{
    public function __construct(
        private VideoProcessingService $processingService,
    ) {
    }

    public function __invoke(ProcessVideoMessage $message): void
    {
        $this->processingService->process($message->versionUid);
    }
}
