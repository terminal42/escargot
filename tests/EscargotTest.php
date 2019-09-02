<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\EventSubscriber\LoggerSubscriber;
use Terminal42\Escargot\Filter\DefaultUriFilter;
use Terminal42\Escargot\Filter\UriFilterInterface;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Tests\Scenario\Scenario;

class EscargotTest extends TestCase
{
    public function testDefaults(): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);

        $this->assertInstanceOf(InMemoryQueue::class, $escargot->getQueue());
        $this->assertInstanceOf(EventDispatcher::class, $escargot->getEventDispatcher());
        $this->assertInstanceOf(HttpClientInterface::class, $escargot->getClient());
        $this->assertInstanceOf(DefaultUriFilter::class, $escargot->getUriFilter());

        $this->assertNotEmpty($escargot->getJobId());
        $this->assertSame(0, $escargot->getMaxRequests());
        $this->assertSame(0, $escargot->getMaxDepth());
        $this->assertSame(10, $escargot->getConcurrency());
        $this->assertSame(0, $escargot->getRequestsSent());
    }

    public function testSetters(): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $uriFilter = $this->createMock(UriFilterInterface::class);

        $escargot->setMaxDepth(5);
        $escargot->setConcurrency(15);
        $escargot->setMaxRequests(500);
        $escargot->setEventDispatcher($eventDispatcher);
        $escargot->setUriFilter($uriFilter);

        $this->assertSame(5, $escargot->getMaxDepth());
        $this->assertSame(15, $escargot->getConcurrency());
        $this->assertSame(500, $escargot->getMaxRequests());
        $this->assertSame($eventDispatcher, $escargot->getEventDispatcher());
        $this->assertSame($uriFilter, $escargot->getUriFilter());
    }

    public function testEmptyBaseUriCollection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create an Escargot instance with an empty BaseUriCollection!');

        $baseUris = new BaseUriCollection();
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);
    }

    public function testFactories(): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);

        $this->assertNotEmpty($escargot->getJobId());
        $this->assertSame($queue, $escargot->getQueue());

        $jobId = $queue->createJobId($baseUris);

        $escargot = Escargot::createFromJobId($jobId, $queue);

        $this->assertSame($jobId, $escargot->getJobId());
        $this->assertSame($queue, $escargot->getQueue());
    }

    public function testInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job ID "foobar" is invalid!');

        $queue = new InMemoryQueue();
        Escargot::createFromJobId('foobar', $queue);
    }

    /**
     * @dataProvider crawlProvider
     */
    public function testCrawl(\Closure $responseFactory, array $expectedLogs, string $message, $options = []): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));

        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue, new MockHttpClient($responseFactory));

        if (0 !== \count($options)) {
            if (\array_key_exists('max_requests', $options)) {
                $escargot->setMaxRequests((int) $options['max_requests']);
            }
            if (\array_key_exists('max_depth', $options)) {
                $escargot->setMaxDepth((int) $options['max_depth']);
            }
            if (\array_key_exists('include_sitemaps', $options)) {
                $escargot->setIncludeSitemaps('false' === $options['include_sitemaps'] ? false : true);
            }
        }

        // Register a test logger which then allows us to very easily assert what's happening based on the logs
        $logger = new TestLogger();
        $escargot->addSubscriber(new LoggerSubscriber($logger));

        $escargot->crawl();

        $filteredRecords = array_map(function (array $record) {
            return $record['message'];
        }, $logger->records);

        $this->assertSame($expectedLogs, $filteredRecords, $message);
    }

    public function crawlProvider(): \Generator
    {
        $finder = new Finder();
        $finder->in([__DIR__.'/Fixtures'])->directories()->sortByName(true);

        if (isset($_SERVER['SCENARIO'])) {
            $finder->name($_SERVER['SCENARIO']);
        }

        foreach ($finder as $scenarioDir) {
            $scenario = new Scenario(ucfirst($scenarioDir->getBasename('.txt')), $scenarioDir->getPathname());

            yield $scenario->getName() => $scenario->getArgumentsForCrawlProvider();
        }
    }
}
