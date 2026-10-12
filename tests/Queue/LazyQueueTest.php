<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Queue;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\LazyQueue;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Tests\Fixture\DoctrineQueueFactory;

final class LazyQueueTest extends AbstractQueueTestCase
{
    public function getQueue(): QueueInterface
    {
        return new LazyQueue(new InMemoryQueue(), DoctrineQueueFactory::create());
    }

    public function testSavingRedirectDoesNotSkipNextUri(): void
    {
        $secondaryQueue = DoctrineQueueFactory::create();
        $baseUri = new Uri('https://example.com/original');
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([$baseUri]));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);
        $crawlUri = $queue->getNext($jobId);
        $this->assertInstanceOf(CrawlUri::class, $crawlUri);

        $crawlUri->markProcessed();
        $queue->add($jobId, $crawlUri);
        $crawlUri->markRedirectedTo(new Uri('https://example.com/target'));
        $queue->add($jobId, $crawlUri);

        $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId)?->getUri());

        $queue->commit($jobId);

        $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId)?->getUri());
        $this->assertSame('https://example.com/target', (string) $secondaryQueue->get($jobId, $baseUri)?->getRedirectedTo());
    }

    #[DataProvider('provideBaseUriProcessingStates')]
    public function testRestoresBaseUriMetadata(bool $processed): void
    {
        $secondaryQueue = DoctrineQueueFactory::create();
        $baseUri = new Uri('https://example.com/original');
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([$baseUri]));
        $crawlUri = new CrawlUri($baseUri, 0, $processed);
        $crawlUri->addTag('restored');
        $crawlUri->markRedirectedTo(new Uri('https://example.com/target'));
        $secondaryQueue->add($jobId, $crawlUri);
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);

        $stored = $queue->get($jobId, $baseUri);
        $this->assertInstanceOf(CrawlUri::class, $stored);
        $this->assertSame($processed, $stored->isProcessed());
        $this->assertTrue($stored->hasTag('restored'));
        $this->assertSame('https://example.com/target', (string) $stored->getRedirectedTo());
    }

    /**
     * @return iterable<array{bool}>
     */
    public static function provideBaseUriProcessingStates(): iterable
    {
        yield 'pending' => [false];
        yield 'processed' => [true];
    }

    public function testProcessedUpdatesDoNotModifySecondaryBeforeCommit(): void
    {
        $secondaryQueue = new InMemoryQueue();
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/last'), 1));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);

        foreach (['https://example.com/original', 'https://example.com/next'] as $uri) {
            $crawlUri = $queue->getNext($jobId);
            $this->assertInstanceOf(CrawlUri::class, $crawlUri);
            $this->assertSame($uri, (string) $crawlUri->getUri());
            $crawlUri->markProcessed();
            $queue->add($jobId, $crawlUri);
        }

        $this->assertSame('https://example.com/last', (string) $queue->getNext($jobId)?->getUri());
        $this->assertSame(3, $secondaryQueue->countPending($jobId));

        $queue->commit($jobId);

        $this->assertSame(1, $secondaryQueue->countPending($jobId));
    }

    public function testPendingSkipCountsAreIndependentForEachJob(): void
    {
        $secondaryQueue = DoctrineQueueFactory::create();
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);

        for ($i = 0; $i < 2; ++$i) {
            $jobId = $secondaryQueue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));
            $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
            $crawlUri = $queue->getNext($jobId);
            $this->assertInstanceOf(CrawlUri::class, $crawlUri);
            $crawlUri->markProcessed();
            $queue->add($jobId, $crawlUri);

            $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId)?->getUri());
        }
    }

    #[DataProvider('provideSecondaryQueues')]
    public function testProcessingCachedUriOutOfOrderDoesNotSkipPendingUri(QueueInterface $secondaryQueue): void
    {
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/last'), 1));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);

        foreach (['https://example.com/original', 'https://example.com/last'] as $uri) {
            $crawlUri = $queue->get($jobId, new Uri($uri));
            $this->assertInstanceOf(CrawlUri::class, $crawlUri);
            $crawlUri->markProcessed();
            $queue->add($jobId, $crawlUri);
        }

        $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId)?->getUri());
    }

    public function testProcessingNewUriDoesNotSkipSecondaryUri(): void
    {
        $secondaryQueue = DoctrineQueueFactory::create();
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);
        $original = $queue->getNext($jobId);
        $this->assertInstanceOf(CrawlUri::class, $original);
        $original->markProcessed();
        $queue->add($jobId, $original);
        $newUri = new CrawlUri(new Uri('https://example.com/new'), 1);
        $newUri->markProcessed();
        $queue->add($jobId, $newUri);

        $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId)?->getUri());
    }

    public function testSkippingAcrossBothQueuesDoesNotReturnCachedUri(): void
    {
        $secondaryQueue = DoctrineQueueFactory::create();
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/next'), 1));
        $secondaryQueue->add($jobId, new CrawlUri(new Uri('https://example.com/last'), 1));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);

        $this->assertSame('https://example.com/last', (string) $queue->getNext($jobId, 2)?->getUri());
        $this->assertNull($queue->getNext($jobId, 3));
        $this->assertSame('https://example.com/original', (string) $queue->getNext($jobId)?->getUri());
        $this->assertSame('https://example.com/next', (string) $queue->getNext($jobId, 1)?->getUri());
    }

    public function testChangesAfterCommitRemainInPrimaryUntilNextCommit(): void
    {
        $secondaryQueue = new InMemoryQueue();
        $uri = new Uri('https://example.com/original');
        $jobId = $secondaryQueue->createJobId(new BaseUriCollection([$uri]));
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);
        $crawlUri = $queue->getNext($jobId);
        $this->assertInstanceOf(CrawlUri::class, $crawlUri);
        $queue->commit($jobId);

        $crawlUri->markProcessed();
        $crawlUri->addTag('updated');
        $queue->add($jobId, $crawlUri);
        $stored = $secondaryQueue->get($jobId, $uri);
        $this->assertInstanceOf(CrawlUri::class, $stored);
        $this->assertFalse($stored->isProcessed());
        $this->assertFalse($stored->hasTag('updated'));

        $queue->commit($jobId);

        $stored = $secondaryQueue->get($jobId, $uri);
        $this->assertInstanceOf(CrawlUri::class, $stored);
        $this->assertTrue($stored->isProcessed());
        $this->assertTrue($stored->hasTag('updated'));
    }

    public function testCanDeleteJobBeforePrimaryQueueIsInitialized(): void
    {
        $secondaryQueue = new InMemoryQueue();
        $queue = new LazyQueue(new InMemoryQueue(), $secondaryQueue);
        $jobId = $queue->createJobId(new BaseUriCollection([new Uri('https://example.com/original')]));

        $queue->deleteJobId($jobId);

        $this->assertFalse($secondaryQueue->isJobIdValid($jobId));
    }

    /**
     * @return iterable<array{QueueInterface}>
     */
    public static function provideSecondaryQueues(): iterable
    {
        yield 'in memory' => [new InMemoryQueue()];
        yield 'doctrine' => [DoctrineQueueFactory::create()];
    }
}
