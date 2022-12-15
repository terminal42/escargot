<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2022, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Queue;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Queue\QueueInterface;

abstract class AbstractQueueTest extends TestCase
{
    abstract public function getQueue(): QueueInterface;

    public function testCanCreateAJobId(): void
    {
        $baseUri = new Uri('https://www.terminal42.ch');
        $baseUris = new BaseUriCollection();
        $baseUris->add($baseUri);

        $queue = $this->getQueue();
        $jobId = $queue->createJobId($baseUris);

        $this->assertNotEmpty($jobId);
        $this->assertTrue($queue->getBaseUris($jobId)->contains($baseUri));
    }

    public function testQueueHandling(): void
    {
        $baseUri = new Uri('https://www.terminal42.ch');
        $baseUri2 = new Uri('https://github.com/');
        $baseUris = new BaseUriCollection();
        $baseUris->add($baseUri);
        $baseUris->add($baseUri2);
        $baseCrawlUri = new CrawlUri($baseUri, 0);
        $baseCrawlUri2 = new CrawlUri($baseUri2, 0);

        $queue = $this->getQueue();
        $jobId = $queue->createJobId($baseUris);

        $this->assertNotNull($queue->get($jobId, $baseCrawlUri->getUri()));
        $this->assertFalse($baseCrawlUri->isProcessed());
        $this->assertNotNull($queue->getNext($jobId));
        $this->assertSame(2, $queue->countAll($jobId));
        $this->assertSame(2, $queue->countPending($jobId));

        $next = $queue->getNext($jobId);

        $this->assertSame((string) $baseCrawlUri, (string) $next);

        $baseCrawlUri->markProcessed();
        $baseCrawlUri->addTag('test-1');
        $baseCrawlUri->addTag('test-2');

        $queue->add($jobId, $baseCrawlUri);

        $next = $queue->getNext($jobId);

        $this->assertSame((string) $baseCrawlUri2, (string) $next);

        $baseCrawlUri2->markProcessed();
        $queue->add($jobId, $baseCrawlUri2);

        $baseCrawlUriFromQueue = $queue->get($jobId, $baseCrawlUri->getUri());

        $this->assertNotNull($baseCrawlUriFromQueue);
        $this->assertTrue($baseCrawlUriFromQueue->isProcessed());
        $this->assertTrue($baseCrawlUriFromQueue->hasTag('test-1'));
        $this->assertTrue($baseCrawlUriFromQueue->hasTag('test-2'));

        $this->assertNull($queue->getNext($jobId));
        $this->assertSame(2, $queue->countAll($jobId));
        $this->assertSame(0, $queue->countPending($jobId));
        $this->assertNull($queue->getNext($jobId));

        // Now let's add the same URI multiple times
        $foobarCrawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, $baseCrawlUri->getUri());
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);
        $queue->add($jobId, $foobarCrawlUri);

        $this->assertSame(3, $queue->countAll($jobId));
        $this->assertSame(1, $queue->countPending($jobId));

        $this->assertSame((string) $foobarCrawlUri, (string) $queue->getNext($jobId));

        // Add another one just to see if the base URI is still returned correctly
        $foobar2CrawlUri = new CrawlUri(new Uri('https://www.terminal42.ch/foobar2'), 2, false, $baseCrawlUri->getUri());
        $queue->add($jobId, $foobar2CrawlUri);

        $this->assertTrue($queue->getBaseUris($jobId)->contains($baseUri));

        // Test the getAll()
        $this->assertInstanceOf(\Generator::class, $queue->getAll($jobId));

        $all = iterator_to_array($queue->getAll($jobId));

        $this->assertInstanceOf(CrawlUri::class, $all[0]);
        $this->assertSame((string) $baseCrawlUri, (string) $all[0]);

        $this->assertInstanceOf(CrawlUri::class, $all[1]);
        $this->assertSame((string) $baseCrawlUri2, (string) $all[1]);

        $this->assertInstanceOf(CrawlUri::class, $all[2]);
        $this->assertSame((string) $foobarCrawlUri, (string) $all[2]);

        $this->assertInstanceOf(CrawlUri::class, $all[3]);
        $this->assertSame((string) $foobar2CrawlUri, (string) $all[3]);

        // Test fetching the next queue entries
        $this->assertSame((string) $foobarCrawlUri, (string) $queue->getNext($jobId));
        $this->assertSame((string) $foobar2CrawlUri, (string) $queue->getNext($jobId, 1));
        $this->assertNull($queue->getNext($jobId, 2));
        $this->assertNull($queue->getNext($jobId, 50));

        // Test delete
        $queue->deleteJobId($jobId);
        $this->assertFalse($queue->isJobIdValid($jobId));
    }

    public function testGetAllOnEmptyQueue(): void
    {
        $queue = $this->getQueue();
        $jobId = $queue->createJobId(new BaseUriCollection());

        $this->assertInstanceOf(\Generator::class, $queue->getAll($jobId));
        $this->assertCount(0, iterator_to_array($queue->getAll($jobId)));
    }
}
