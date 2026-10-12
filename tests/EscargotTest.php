<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\Exception\ClientAlreadyCustomizedException;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\ExceptionSubscriberInterface;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\TagValueResolvingSubscriberInterface;
use Terminal42\Escargot\SubscriberLogger;
use Terminal42\Escargot\SubscriberLoggerTrait;
use Terminal42\Escargot\Tests\Scenario\MockResponseFactory;
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
        $logger = new TestLogger();

        $escargot = Escargot::create($baseUris, $queue);

        $subscriber = $this->createMock(CompleteSubscriber::class);
        $subscriber
            ->expects($this->exactly(5))
            ->method('setEscargot')
        ;

        $subscriber
            ->expects($this->once())
            ->method('setLogger')
            ->with($this->callback(
                // Must be decorated
                static fn (LoggerInterface $logger) => $logger instanceof SubscriberLogger,
            ))
        ;

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

    public function testMaxDuration(): void
    {
        $mockResponse = <<<'HTML'
            HTTP/2.0 200 OK
            content-type: text/html; charset=UTF-8

            <html>
                <head>
                </head>
                <body>
                    <a href="https://www.terminal42.ch/%s">Link</a>
                </body>
            </html>
            HTML;

        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();
        $clock = new MockClock();
        $client = new MockHttpClient(
            static function ($method, $url) use ($clock, $mockResponse) {
                $clock->sleep(1); // Mock the request that takes a second to complete

                return MockResponseFactory::createFromString(\sprintf($mockResponse, uniqid()));
            },
        );
        $logger = new TestLogger();

        $escargot = Escargot::create($baseUris, $queue)
            ->withLogger($logger)
            ->withHttpClient($client)
            ->withMaxDurationInSeconds(5)
            ->withClock($clock)
        ;

        $escargot->addSubscriber(new HtmlCrawlerSubscriber());
        $escargot->addSubscriber($this->getSearchIndexSubscriber());

        $escargot->crawl();

        $this->assertSame(
            [
                '[Terminal42\Escargot\Escargot] Configured max duration reached!',
                '[Terminal42\Escargot\Escargot] Finished crawling! Sent 5 request(s).',
            ],
            $this->cleanLogs($logger),
        );
    }

    #[DataProvider('crawlProvider')]
    public function testCrawlAsWebCrawler(\Closure $responseFactory, array $expectedLogs, array $expectedRequests, string $message, $options = []): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));

        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);
        $escargot = $escargot->withHttpClient(new MockHttpClient($responseFactory));

        if (0 !== (is_countable($options) ? \count($options) : 0)) {
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

        $filteredLogs = $this->cleanLogs($logger);

        $this->assertSame($expectedLogs, $filteredLogs, $message);

        $filteredRequests = array_map(static fn (CrawlUri $crawlUri) => \sprintf('Successful request! %s.', (string) $crawlUri), $indexerSubscriber->getUris());

        $this->assertSame($expectedRequests, $filteredRequests, $message);
    }

    public static function crawlProvider(): iterable
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

    #[DataProvider('provideRequestFailures')]
    public function testRequestFailure(bool $precedingResponse, int $concurrency): void
    {
        $uris = $precedingResponse ? [new Uri('https://example.com/before')] : [];
        $uris[] = new Uri('https://example.com/failure');
        $uris[] = new Uri('https://example.com/after');
        $exception = new TransportException('Request failed before returning a response');
        $logger = new TestLogger();
        $client = new MockHttpClient(
            static function (string $method, string $url) use ($exception): MockResponse {
                if ('https://example.com/failure' === $url) {
                    throw $exception;
                }

                return new MockResponse('Success');
            },
        );
        $escargot = Escargot::create(new BaseUriCollection($uris), new InMemoryQueue())
            ->withHttpClient($client)
            ->withConcurrency($concurrency)
            ->withLogger($logger)
        ;
        $subscriber = $this->createInterestedSubscriber($exception, \count($uris) - 1);
        $escargot->addSubscriber($subscriber);
        $escargot->addSubscriber($this->createUninterestedSubscriber());

        $escargot->crawl();

        $this->assertSame(\count($uris) - 1, $client->getRequestsCount());
        $this->assertSame(\count($uris) - 1, $escargot->getRequestsSent());
        $this->assertTrue($logger->hasDebugThatContains($exception->getMessage()));
    }

    /**
     * @return iterable<array{bool, int}>
     */
    public static function provideRequestFailures(): iterable
    {
        yield 'first request, sequential' => [false, 1];
        yield 'first request, concurrent' => [false, 2];
        yield 'after success, sequential' => [true, 1];
        yield 'after success, concurrent' => [true, 2];
    }

    #[DataProvider('provideResponseFailures')]
    public function testFailureWithExistingResponse(bool $transportFailure): void
    {
        $body = $transportFailure ? (
            static function (): iterable {
                yield new TransportException('Response stream failed');
            }
        )() : 'Service unavailable';
        $response = new MockResponse($body, ['http_code' => $transportFailure ? 200 : 503]);
        $escargot = Escargot::create(new BaseUriCollection([new Uri('https://example.com/')]), new InMemoryQueue())
            ->withHttpClient(new MockHttpClient($response))
        ;
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->method('needsContent')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->once())
            ->method($transportFailure ? 'onTransportException' : 'onHttpException')
            ->with(
                $this->isInstanceOf(CrawlUri::class),
                $this->isInstanceOf($transportFailure ? TransportExceptionInterface::class : HttpExceptionInterface::class),
                $this->callback(static fn (ResponseInterface $response): bool => 'https://example.com/' === $response->getInfo('url')
                    && false === $response->getInfo('canceled')),
            )
        ;

        $subscriber
            ->expects($this->never())
            ->method($transportFailure ? 'onHttpException' : 'onTransportException')
        ;
        $escargot->addSubscriber($subscriber);

        $escargot->crawl();

        $this->assertSame(1, $escargot->getRequestsSent());
    }

    /**
     * @return iterable<array{bool}>
     */
    public static function provideResponseFailures(): iterable
    {
        yield 'transport failure during streaming' => [true];
        yield 'HTTP failure' => [false];
    }

    public function testHttpExceptionFromAuxiliaryRequestPreservesCrawlResponse(): void
    {
        $auxiliaryClient = new MockHttpClient(new MockResponse('Unavailable', ['http_code' => 503]));
        $auxiliaryResponse = $auxiliaryClient->request('GET', 'https://example.com/auxiliary');
        $crawlUri = new CrawlUri(new Uri('https://example.com/crawled'), 0);
        $escargot = Escargot::create(new BaseUriCollection([$crawlUri->getUri()]), new InMemoryQueue())
            ->withHttpClient(new MockHttpClient(new MockResponse('OK')))
        ;
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->method('needsContent')
            ->willReturnCallback(
                static function (CrawlUri $crawlUri, ResponseInterface $response) use ($auxiliaryResponse): string {
                    $auxiliaryResponse->getHeaders();

                    return SubscriberInterface::DECISION_POSITIVE;
                },
            )
        ;

        $subscriber
            ->expects($this->once())
            ->method('onHttpException')
            ->with(
                $this->callback(static fn (CrawlUri $crawlUri): bool => 'https://example.com/crawled' === (string) $crawlUri->getUri()),
                $this->callback(static fn (HttpExceptionInterface $exception): bool => $auxiliaryResponse === $exception->getResponse()),
                $this->callback(static fn (ResponseInterface $response): bool => 'https://example.com/crawled' === $response->getInfo('url')
                    && 'https://example.com/crawled' === (string) $response->getInfo('user_data')->getUri()),
            )
        ;
        $escargot->addSubscriber($subscriber);

        $escargot->crawl();

        $this->assertSame(1, $escargot->getRequestsSent());
    }

    private function cleanLogs(TestLogger $testLogger): array
    {
        return array_map(
            static function (array $record) {
                $message = $record['message'];

                if (isset($record['context']['crawlUri'])) {
                    $message = \sprintf('[%s] %s', (string) $record['context']['crawlUri'], $message);
                }

                if (isset($record['context']['source'])) {
                    $message = \sprintf('[%s] %s', $record['context']['source'], $message);
                }

                return $message;
            },
            $testLogger->records,
        );
    }

    private function getSearchIndexSubscriber(): SubscriberInterface
    {
        return new class() implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface {
            use EscargotAwareTrait;
            use LoggerAwareTrait;
            use SubscriberLoggerTrait;

            private array $uris = [];

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
                            'Do not request because when the crawl URI was found, the robots information disallowed following this URI.',
                        );

                        return SubscriberInterface::DECISION_NEGATIVE;
                    }
                }

                // Skip the links that are disallowed by robots.txt
                if ($crawlUri->hasTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because it was disallowed by the robots.txt.',
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                // Skip rel="nofollow" links
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_REL_NOFOLLOW)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because when the crawl URI was found, the "rel" attribute contained "nofollow".',
                    );

                    return SubscriberInterface::DECISION_NEGATIVE;
                }

                // Skip the links that have the "type" attribute set and it's not text/html
                if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_NO_TEXT_HTML_TYPE)) {
                    $this->logWithCrawlUri(
                        $crawlUri,
                        LogLevel::DEBUG,
                        'Do not request because when the crawl URI was found, the "type" attribute was present and did not contain "text/html".',
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

    private function createInterestedSubscriber(TransportException $exception, int $successfulRequests): SubscriberInterface
    {
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->method('needsContent')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->exactly($successfulRequests))
            ->method('onLastChunk')
        ;

        $subscriber
            ->expects($this->never())
            ->method('onHttpException')
        ;

        $subscriber
            ->expects($this->once())
            ->method('onTransportException')
            ->with(
                $this->callback(static fn (CrawlUri $crawlUri): bool => 'https://example.com/failure' === (string) $crawlUri->getUri()),
                $exception,
                null,
            )
        ;

        return $subscriber;
    }

    private function createUninterestedSubscriber(): SubscriberInterface
    {
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_NEGATIVE)
        ;

        $subscriber
            ->expects($this->never())
            ->method('onTransportException')
        ;

        return $subscriber;
    }
}

abstract class CompleteSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
}
