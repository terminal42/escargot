<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Queue;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\QueueInterface;

class DoctrineQueueTest extends AbstractQueueTest
{
    /**
     * @var DoctrineQueue
     */
    private $queue;

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
        return $this->queue;
    }

    public function testGetTableSchema(): void
    {
        $this->assertInstanceOf(Table::class, $this->queue->getTableSchema());
    }
}
