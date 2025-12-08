<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Queue;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\LazyQueue;
use Terminal42\Escargot\Queue\QueueInterface;

class LazyQueueTestCase extends AbstractQueueTestCase
{
    private DoctrineQueue $queue;

    protected function setUp(): void
    {
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        $connection = DriverManager::getConnection(
            (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse('sqlite://:memory:'),
            $configuration,
        );

        $this->queue = new DoctrineQueue($connection, static fn () => 'foobar');

        $this->queue->createSchema();
    }

    public function getQueue(): QueueInterface
    {
        return new LazyQueue(new InMemoryQueue(), $this->queue);
    }
}
