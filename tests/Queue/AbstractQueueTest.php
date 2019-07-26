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
        $baseCrawlUri = new CrawlUri($baseUri, 0);

        $queue = $this->getQueue();
        $jobId = $queue->createJobId($baseUri);

        $this->assertNotNull($queue->get($jobId, $baseCrawlUri->getUri()));
        $this->assertFalse($baseCrawlUri->isProcessed());
        $this->assertNotNull($queue->getNext($jobId));
        $this->assertSame(1, $queue->countAll($jobId));
        $this->assertSame(1, $queue->countPending($jobId));

        $next = $queue->getNext($jobId);

        $this->assertSame((string) $baseCrawlUri, (string) $next);

        $baseCrawlUri->markProcessed();
        $queue->add($jobId, $baseCrawlUri);

        $this->assertNotNull($queue->get($jobId, $baseCrawlUri->getUri()));
        $this->assertTrue($baseCrawlUri->isProcessed());
        $this->assertNull($queue->getNext($jobId));
        $this->assertSame(1, $queue->countAll($jobId));
        $this->assertSame(0, $queue->countPending($jobId));
        $this->assertNull($queue->getNext($jobId));

        // Now let's add the same URI multiple times
        $foobarCrawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1);
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);

        $this->assertSame(2, $queue->countAll($jobId));
        $this->assertSame(1, $queue->countPending($jobId));

        // Add another one just to see if the base URI is still returned correctly
        $foobar2CrawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar2'), 2);
        $queue->add($jobId, $foobar2CrawlUri);

        $this->assertSame((string) $baseUri, (string) $queue->getBaseUri($jobId));

        // Test the getAll()
        $this->assertInstanceOf(\Generator::class, $queue->getAll($jobId));

        $all = iterator_to_array($queue->getAll($jobId));

        $this->assertInstanceOf(CrawlUri::class, $all[0]);
        $this->assertSame((string) $baseCrawlUri, (string) $all[0]);

        $this->assertInstanceOf(CrawlUri::class, $all[1]);
        $this->assertSame((string) $foobarCrawlUri, (string) $all[1]);

        $this->assertInstanceOf(CrawlUri::class, $all[2]);
        $this->assertSame((string) $foobar2CrawlUri, (string) $all[2]);
    }
}
