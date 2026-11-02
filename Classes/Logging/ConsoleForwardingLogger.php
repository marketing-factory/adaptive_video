<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Logs to the given logger and additionally to the worker console, if any.
 */
final class ConsoleForwardingLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WorkerConsole $workerConsole,
    ) {
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
        $this->workerConsole->getLogger()?->log($level, $message, $context);
    }
}
