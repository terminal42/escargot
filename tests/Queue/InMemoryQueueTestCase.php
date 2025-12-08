<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Queue;

use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\QueueInterface;

class InMemoryQueueTestCase extends AbstractQueueTestCase
{
    public function getQueue(): QueueInterface
    {
        return new InMemoryQueue();
    }
}
