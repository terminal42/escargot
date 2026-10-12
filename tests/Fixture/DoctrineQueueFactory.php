<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Fixture;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Terminal42\Escargot\Queue\DoctrineQueue;

final class DoctrineQueueFactory
{
    public static function create(): DoctrineQueue
    {
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $queue = new DoctrineQueue($connection, static fn (): string => bin2hex(random_bytes(16)));
        $queue->createSchema();

        return $queue;
    }
}
