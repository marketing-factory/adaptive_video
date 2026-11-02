<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Tests\Functional\Messenger;

use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Mfd\AdaptiveVideo\Messenger\Message\ProcessVideoMessage;
use Mfd\AdaptiveVideo\Messenger\TransportFactory;
use Mfd\AdaptiveVideo\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class TransportFactoryTest extends AbstractFunctionalTestCase
{
    public function testTransportWorksWithPortConfiguredAsString(): void
    {
        // e.g. $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] = getenv('DATABASE_PORT');
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] = (string)($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['port'] ?? '3306');
        $subject = new TransportFactory(new PhpSerializer(), ExtensionSettings::fromArray([]));

        $transport = $subject->create();
        $transport->send(new Envelope(new ProcessVideoMessage(1)));

        self::assertSame(1, $transport->getMessageCount());
        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        self::assertEquals(new ProcessVideoMessage(1), $envelopes[0]->getMessage());
    }
}
