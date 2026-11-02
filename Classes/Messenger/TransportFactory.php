<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Messenger;

use Doctrine\DBAL\DriverManager;
use Mfd\AdaptiveVideo\Configuration\ExtensionSettings;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Own queue in sys_messenger_messages, so long encodings do not block other
 * asynchronous messages. The redeliver timeout comes from the extension
 * configuration, which is why the transport is not configured statically.
 *
 * Mirrors the internal TYPO3\CMS\Core\Messenger\DoctrineTransportFactory, but
 * casts the port to int like the ConnectionPool does: a port from getenv() is
 * a string and breaks the mysqli driver.
 */
final readonly class TransportFactory
{
    public const TRANSPORT_NAME = 'adaptive_video';

    public function __construct(
        private SerializerInterface $serializer,
        private ExtensionSettings $settings,
    ) {
    }

    public function create(): DoctrineTransport
    {
        $connectionParams = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'];
        if (array_key_exists('port', $connectionParams)) {
            $connectionParams['port'] = (int)$connectionParams['port'];
        }

        // Plain DBAL connection: the TYPO3 QueryBuilder is not fully compatible with Symfony Messenger
        $connection = DriverManager::getConnection($connectionParams);

        return new DoctrineTransport(
            new Connection([
                'table_name' => 'sys_messenger_messages',
                'queue_name' => self::TRANSPORT_NAME,
                'redeliver_timeout' => $this->settings->redeliverTimeout,
                // The table is managed by the TYPO3 core schema
                'auto_setup' => false,
            ], $connection),
            $this->serializer
        );
    }
}
