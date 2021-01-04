<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2021, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Queue;

use Doctrine\DBAL\DriverManager;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\QueueInterface;

class DoctrineQueueTest extends AbstractQueueTest
{
    /**
     * @var DoctrineQueue
     */
    private $queue;

    public function setUp(): void
    {
        $this->queue = new DoctrineQueue(DriverManager::getConnection(['url' => 'sqlite://:memory:']), function () {
            return 'foobar';
        });

        $this->queue->createSchema();
    }

    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }
}
