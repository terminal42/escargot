<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Subscriber;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\QueueInterface;

abstract class AbstractSubscriberTest extends TestCase
{
    protected function createEscargot(LoggerInterface $logger = null, QueueInterface $queue = null, HttpClientInterface $client = null): Escargot
    {
        $queue = $queue ?? new InMemoryQueue();
        $client = $client ?? $this->createMock(HttpClientInterface::class);

        $escargot = Escargot::create(
            new BaseUriCollection([new Uri('https://www.terminal42.ch')]),
            $queue,
            $client
        );

        if ($logger) {
            $escargot = $escargot->withLogger($logger);
        }

        return $escargot;
    }
}
