<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests;

use Beste\Psr\Log\Record;
use Beste\Psr\Log\TestLogger;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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
use Terminal42\Escargot\Exception\ClientAlreadyCustomizedException;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\TagValueResolvingSubscriberInterface;
use Terminal42\Escargot\SubscriberLogger;
use Terminal42\Escargot\SubscriberLoggerTrait;
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
        $logger = TestLogger::create();

        $escargot = Escargot::create($baseUris, $queue);

        $subscriber = $this->createMock(CompleteSubscriber::class);
        $subscriber
            ->expects($this->exactly(5))
            ->method('setEscargot');
        $subscriber
            ->expects($this->once())
            ->method('setLogger')
            ->with($this->callback(function (LoggerInterface $logger) {
                // Must be decorated
                return $logger instanceof SubscriberLogger;
            }));

        $escargot->addSubscriber($subscriber);

        $escargot = $escargot->withConcurrency(15);
        $escargot = $escargot->withMaxRequests(500);
        $escargot = $escargot->withMaxDepth(10);
        $escargot = $escargot->withLogger($logger);

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

        Escargot::create($baseUris, $queue);
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

    public function testCannotChangeUserAgentIfAlreadyCustomizedHttpClient(): void
    {
        $this->expectException(ClientAlreadyCustomizedException::class);
        $this->expectExceptionMessage('Cannot override user agent, as you have already customized the client.');

        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));

        $escargot = Escargot::create($baseUris, new InMemoryQueue());
        $escargot = $escargot->withHttpClient(new MockHttpClient());

        $escargot->withUserAgent('custom/user-agent');
    }

    public function testInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job ID "foobar" is invalid!');

        $queue = new InMemoryQueue();
        Escargot::createFromJobId('foobar', $queue);
    }

    public function testResolveTagValue(): void
    {
        $tagValueResolvingSubscriber = new class() implements SubscriberInterface, TagValueResolvingSubscriberInterface {
            public function shouldRequest(CrawlUri $crawlUri): string
            {
                return self::DECISION_NEGATIVE;
            }

            public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
            {
                return self::DECISION_NEGATIVE;
            }

            public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
            {
            }

            public function resolveTagValue(string $tag)
            {
                if ('foobar' === $tag) {
                    return 'success';
                }

                return null;
            }
        };

        $baseUris = new BaseUriCollection([new Uri('https://www.terminal42.ch/')]);
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);
        $escargot->addSubscriber($tagValueResolvingSubscriber);

        $this->assertSame('success', $escargot->resolveTagValue('foobar'));
    }

    /**
     * @dataProvider crawlProvider
     */
    public function testCrawlAsWebCrawler(\Closure $responseFactory, array $expectedLogs, array $expectedRequests, string $message, $options = []): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));

        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);
        $escargot = $escargot->withHttpClient(new MockHttpClient($responseFactory));

        if (0 !== \count($options)) {
            if (\array_key_exists('max_requests', $options)) {
                $escargot = $escargot->withMaxRequests((int) $options['max_requests']);
            }
            if (\array_key_exists('max_depth', $options)) {
                $escargot = $escargot->withMaxDepth((int) $options['max_depth']);
            }
        }

        // Register a test logger which then allows us to very easily assert what's happening based on the logs
        $logger = TestLogger::create();
        $escargot = $escargot->withLogger($logger);

        // Add subscribers
        $escargot->addSubscriber(new RobotsSubscriber());
        $escargot->addSubscriber(new HtmlCrawlerSubscriber());

        // We also add a subscriber that shall request the crawl URIs that match the BaseUriCollection.
        $indexerSubscriber = $this->getSearchIndexSubscriber();
        $escargot->addSubscriber($indexerSubscriber);

        $escargot->crawl();

        $filteredLogs = array_map(function (Record $record) {
            $message = $record->message;

            if (isset($record->context->data['crawlUri'])) {
                $message = sprintf('[%s] %s', (string) $record->context->data['crawlUri'], $message);
            }

            if (isset($record->context->data['source'])) {
                $message = sprintf('[%s] %s', $record->context->data['source'], $message);
            }

            return $message;
        }, $logger->records->all());

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
        return new class() implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface {
            use EscargotAwareTrait;
            use LoggerAwareTrait;
            use SubscriberLoggerTrait;

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
                        $this->logWithCrawlUri(
                            $crawlUri,
                            LogLevel::DEBUG,
                            'Do not request because when the crawl URI was found, the robots information disallowed following this URI.'
                        );

                        return SubscriberInterface::DECISION_NEGATIVE;
                    }
                }

                // Skip the links that are disallowed by robots.txt
                if ($crawlUri->hasTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because it was disallowed by the robots.txt.'
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                // Skip rel="nofollow" links
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_REL_NOFOLLOW)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because when the crawl URI was found, the "rel" attribute contained "nofollow".'
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                // Skip the links that have the "type" attribute set and it's not text/html
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_NO_TEXT_HTML_TYPE)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because when the crawl URI was found, the "type" attribute was present and did not contain "text/html".'
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
                return SubscriberInterface::DECISION_POSITIVE;
            }

            public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
            {
                $this->uris[] = $crawlUri;
            }
        };
    }
}

abstract class CompleteSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
}
