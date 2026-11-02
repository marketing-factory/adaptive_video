<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Unit\Logging;

use Mfd\AdaptiveVideo\Logging\ConsoleForwardingLogger;
use Mfd\AdaptiveVideo\Logging\WorkerConsole;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ConsoleForwardingLoggerTest extends UnitTestCase
{
    private function createWorkerConsole(string $commandName, BufferedOutput $output): WorkerConsole
    {
        $workerConsole = new WorkerConsole();
        $workerConsole->onConsoleCommand(new ConsoleCommandEvent(new Command($commandName), new ArrayInput([]), $output));

        return $workerConsole;
    }

    public function testEntriesAreForwardedToTheConsoleOfTheConsumer(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects(self::once())->method('log')->with('info', 'Version {versionUid} is ready.', ['versionUid' => 7]);

        $logger = new ConsoleForwardingLogger($inner, $this->createWorkerConsole('messenger:consume', $output));
        $logger->info('Version {versionUid} is ready.', ['versionUid' => 7]);

        self::assertStringContainsString('Version 7 is ready.', $output->fetch());
    }

    public function testInfoIsHiddenBelowVeryVerbose(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $logger = new ConsoleForwardingLogger($this->createStub(LoggerInterface::class), $this->createWorkerConsole('messenger:consume', $output));
        $logger->info('Version 7 is ready.');

        self::assertSame('', $output->fetch());
    }

    public function testOtherCommandsDoNotGetConsoleOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

        $logger = new ConsoleForwardingLogger($this->createStub(LoggerInterface::class), $this->createWorkerConsole('adaptive-video:scan', $output));
        $logger->error('Processing failed.');

        self::assertSame('', $output->fetch());
    }
}
