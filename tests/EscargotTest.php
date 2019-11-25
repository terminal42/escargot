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
use Psr\Log\LogLevel;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\Util;
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

        $subscriber = $this->createMock([SubscriberInterface::class, EscargotAwareInterface::class]);
        $subscriber
            ->expects($this->exactly(4))
            ->method('setEscargot');

        $escargot->addSubscriber($subscriber);

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

        // We also add a subscriber that shall request the crawl URIs that match the BaseUriCollection.
        $indexerSubscriber = $this->getSearchIndexSubscriber();
        $escargot->addSubscriber($indexerSubscriber);

        $escargot->crawl();

        $filteredLogs = array_map(function (array $record) {
            $message = $record['message'];

            if (isset($record['context']['source'])) {
                $message = sprintf('[%s] %s', $record['context']['source'], $message);
            }

            return $message;
        }, $logger->records);

        $this->assertSame($expectedLogs, $filteredLogs, $message);

        $filteredRequests = array_map(function (CrawlUri $crawlUri) {
            return sprintf('Successful request! %s.', (string) $crawlUri);
        }, $indexerSubscriber->getUris());

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

    private function getSearchIndexSubscriber(): SubscriberInterface
    {
        return new class() implements SubscriberInterface, EscargotAwareInterface {
            use EscargotAwareTrait;

            private $uris = [];

            public function getUris(): array
            {
                return $this->uris;
            }

            public function shouldRequest(CrawlUri $crawlUri): string
            {
                // Check the original crawlUri to see if that one contained nofollow information
                if (null !== $crawlUri->getFoundOn() && ($originalCrawlUri = $this->escargot->getCrawlUri($crawlUri->getFoundOn()))) {
                    if ($originalCrawlUri->hasTag(RobotsSubscriber::TAG_NOFOLLOW)) {
                        $this->escargot->log(
                            LogLevel::DEBUG,
                            $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the robots information disallowed following this URI.'),
                            ['source' => 'Unit-Test-Search-Index-Subscriber']
                        );

                        return SubscriberInterface::DECISION_NEGATIVE;
                    }
                }

                // Skip rel="nofollow" links
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_REL_NOFOLLOW)) {
                    $this->escargot->log(
                        LogLevel::DEBUG,
                        $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the "rel" attribute contained "nofollow".'),
                        ['source' => 'Unit-Test-Search-Index-Subscriber']
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                // Skip the links that have the "type" attribute set and it's not text/html
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_NO_TEXT_HTML_TYPE)) {
                    $this->escargot->log(
                        LogLevel::DEBUG,
                        $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the "type" attribute was present and did not contain "text/html".'),
                        ['source' => 'Unit-Test-Search-Index-Subscriber']
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                if ($this->escargot->getBaseUris()->containsHost($crawlUri->getUri()->getHost())) {
                    return SubscriberInterface::DECISION_POSITIVE;
                }

                return SubscriberInterface::DECISION_ABSTAIN;
            }

            public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
            {
                return 200 === $response->getStatusCode() && Util::isOfContentType($response, 'text/html') ? SubscriberInterface::DECISION_POSITIVE : SubscriberInterface::DECISION_NEGATIVE;
            }

            public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
            {
                $this->uris[] = $crawlUri;
            }
        };
    }
}
