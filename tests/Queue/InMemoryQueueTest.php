<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2020, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Queue;

use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\QueueInterface;

class InMemoryQueueTest extends AbstractQueueTest
{
    public function getQueue(): QueueInterface
    {
        return new InMemoryQueue();
    }
}
