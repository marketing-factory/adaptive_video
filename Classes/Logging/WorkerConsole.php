<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Logger\ConsoleLogger;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Holds a console logger while `messenger:consume` runs, so that log entries
 * of the processing also show up in the worker output (notice from -v, info
 * from -vv, debug from -vvv).
 */
final class WorkerConsole
{
    private ?LoggerInterface $logger = null;

    #[AsEventListener]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() === 'messenger:consume') {
            $this->logger = new ConsoleLogger($event->getOutput());
        }
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
