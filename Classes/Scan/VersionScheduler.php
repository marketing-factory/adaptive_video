<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Scan;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Domain\Repository\VersionRepository;
use Mfd\AdaptiveVideo\Messenger\Message\ProcessVideoMessage;
use Mfd\AdaptiveVideo\Messenger\TransportFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Creates a pending version for a file and hands it to the worker queue.
 */
final readonly class VersionScheduler
{
    public function __construct(
        private VersionRepository $versionRepository,
        private MessageBusInterface $messageBus,
        private ExtensionSettings $settings,
    ) {
    }

    /**
     * Returns the uid of the new version, or null if processing is disabled
     * or a version for this content and profile already exists.
     */
    public function schedule(int $fileUid, string $sourceFingerprint): ?int
    {
        if (!$this->settings->processingEnabled) {
            return null;
        }

        $versionUid = $this->versionRepository->create($fileUid, $sourceFingerprint, $this->settings->createProfileSnapshot());
        if ($versionUid === null) {
            return null;
        }

        try {
            // Explicit transport: with routing only, TYPO3 would additionally
            // send the message to the "*" fallback (synchronous) transport
            $this->messageBus->dispatch(
                new ProcessVideoMessage($versionUid),
                [new TransportNamesStamp([TransportFactory::TRANSPORT_NAME])]
            );
        } catch (\Throwable $e) {
            // Without a message the version would stay pending forever and
            // block the next scan from scheduling the file again
            $this->versionRepository->delete($versionUid);
            throw $e;
        }

        return $versionUid;
    }
}
