<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Queue;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Queue\QueueInterface;

abstract class AbstractQueueTest extends TestCase
{
    abstract public function getQueue(): QueueInterface;

    public function testCanCreateAJobId(): void
    {
        $baseUri = new Uri('https://www.terminal42.ch');

        $queue = $this->getQueue();
        $jobId = $queue->createJobId($baseUri);

        $this->assertNotEmpty($jobId);
        $this->assertSame((string) $baseUri, (string) $queue->getBaseUri($jobId));
    }

    public function testQueueHandling(): void
    {
        $baseUri = new Uri('https://www.terminal42.ch');
        $crawlUri = new CrawlUri($baseUri, 0);

        $queue = $this->getQueue();
        $jobId = $queue->createJobId($baseUri);

        $this->assertTrue($queue->has($jobId, $crawlUri));
        $this->assertFalse($queue->isProcessed($jobId, $crawlUri));
        $this->assertTrue($queue->hasPending($jobId));
        $this->assertSame(1, $queue->countAll($jobId));
        $this->assertSame(1, $queue->countPending($jobId));

        $next = $queue->getNext($jobId);

        $this->assertSame((string) $crawlUri, (string) $next);

        $queue->markProcessed($jobId, $crawlUri);

        $this->assertTrue($queue->has($jobId, $crawlUri));
        $this->assertTrue($queue->isProcessed($jobId, $crawlUri));
        $this->assertFalse($queue->hasPending($jobId));
        $this->assertSame(1, $queue->countAll($jobId));
        $this->assertSame(0, $queue->countPending($jobId));
        $this->assertNull($queue->getNext($jobId));

        // Now let's add the same URI multiple times
        $crawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1);
        $queue->add($jobId, $crawlUri);
        $queue->add($jobId, $crawlUri);
        $queue->add($jobId, $crawlUri);
        $queue->add($jobId, $crawlUri);

        $this->assertSame(2, $queue->countAll($jobId));
        $this->assertSame(1, $queue->countPending($jobId));

        // Add another one just to see if the base URI is still returned correctly
        $crawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar2'), 2);
        $queue->add($jobId, $crawlUri);

        $this->assertSame((string) $baseUri, (string) $queue->getBaseUri($jobId));

        // Test the getAll()
        $this->assertInstanceOf(\Generator::class, $queue->getAll($jobId));

        foreach ($queue->getAll($jobId) as $crawlUri) {
            $this->assertInstanceOf(CrawlUri::class, $crawlUri);
        }
    }
}
