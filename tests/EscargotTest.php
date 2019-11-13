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
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
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
        $this->assertInstanceOf(HttpClientInterface::class, $escargot->getClient());

        $this->assertNotEmpty($escargot->getJobId());
        $this->assertSame(0, $escargot->getMaxRequests());
        $this->assertSame(10, $escargot->getConcurrency());
        $this->assertSame(0, $escargot->getRequestsSent());
    }

    public function testWithers(): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);

        $escargot = $escargot->withConcurrency(15);
        $escargot = $escargot->withMaxRequests(500);
        $escargot = $escargot->withMaxDepth(10);

        $this->assertSame(15, $escargot->getConcurrency());
        $this->assertSame(500, $escargot->getMaxRequests());
        $this->assertSame(10, $escargot->getMaxDepth());
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
    public function testCrawlAsWebCrawler(\Closure $responseFactory, array $expectedLogs, array $expectedRequests, string $message, $options = []): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));

        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue, new MockHttpClient($responseFactory));

        if (0 !== \count($options)) {
            if (\array_key_exists('max_requests', $options)) {
                $escargot = $escargot->withMaxRequests((int) $options['max_requests']);
            }
            if (\array_key_exists('max_depth', $options)) {
                $escargot = $escargot->withMaxDepth((int) $options['max_depth']);
            }
        }

        // Register a test logger which then allows us to very easily assert what's happening based on the logs
        $logger = new TestLogger();
        $escargot = $escargot->withLogger($logger);

        // Add subscribers
        $escargot->addSubscriber(new RobotsSubscriber());
        $escargot->addSubscriber(new HtmlCrawlerSubscriber());

        // We also add a subscriber that shall log all the successful requests
        $collector = $this->getSuccessfulResponseCollectorSubscriber();
        $escargot->addSubscriber($collector);

        $escargot->crawl();

        $filteredLogs = array_map(function (array $record) {
            return $record['message'];
        }, $logger->records);

        $this->assertSame($expectedLogs, $filteredLogs, $message);

        $filteredRequests = array_map(function (CrawlUri $crawlUri) {
            return sprintf('Successful request! %s.', (string) $crawlUri);
        }, $collector->getUris());

        $this->assertSame($expectedRequests, $filteredRequests, $message);
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

    private function getSuccessfulResponseCollectorSubscriber(): SubscriberInterface
    {
        return new class() implements SubscriberInterface {
            private $uris = [];

            public function getUris(): array
            {
                return $this->uris;
            }

            public function shouldRequest(CrawlUri $crawlUri, string $currentDecision): string
            {
                return $currentDecision;
            }

            public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk, string $currentDecision): string
            {
                if (200 === $response->getStatusCode()) {
                    $this->uris[] = $crawlUri;
                }

                return $currentDecision;
            }

            public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
            {
                // noop
            }
        };
    }
}
