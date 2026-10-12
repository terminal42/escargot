<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Exception\ClientAlreadyCustomizedException;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\LazyQueue;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Subscriber\ExceptionSubscriberInterface;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\TagValueResolvingSubscriberInterface;
use Terminal42\Escargot\SubscriberLogger;
use Terminal42\Escargot\Tests\Fixture\AbstractCompleteSubscriber;
use Terminal42\Escargot\Tests\Fixture\DoctrineQueueFactory;
use Terminal42\Escargot\Tests\Fixture\SearchIndexSubscriber;
use Terminal42\Escargot\Tests\Scenario\MockResponseFactory;
use Terminal42\Escargot\Tests\Scenario\Scenario;

final class EscargotTest extends TestCase
{
    public function testDefaults(): void
    {
        $baseUris = new BaseUriCollection();
        $baseUris->add(new Uri('https://www.terminal42.ch'));
        $queue = new InMemoryQueue();

        $escargot = Escargot::create($baseUris, $queue);

        $this->assertInstanceOf(InMemoryQueue::class, $escargot->getQueue());

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

        $subscriber = $this->createMock(AbstractCompleteSubscriber::class);
        $subscriber
            ->expects($this->exactly(5))
            ->method('setEscargot')
        ;

        $subscriber
            ->expects($this->once())
            ->method('setLogger')
            ->with(
                // Must be decorated
                $this->isInstanceOf(SubscriberLogger::class),
            )
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
        $escargot->addSubscriber(new SearchIndexSubscriber());

        $escargot->crawl();

        $this->assertSame(
            [
                '[Terminal42\Escargot\Escargot] Configured max duration reached!',
                '[Terminal42\Escargot\Escargot] Finished crawling! Sent 5 request(s).',
            ],
            $this->cleanLogs($logger),
        );
    }

    /**
     * @param \Closure(string, string, array<string, mixed>): MockResponse $responseFactory
     * @param array<string>                                                $expectedLogs
     * @param array<string>                                                $expectedRequests
     * @param array<string, string>                                        $options
     */
    #[DataProvider('crawlProvider')]
    public function testCrawlAsWebCrawler(\Closure $responseFactory, array $expectedLogs, array $expectedRequests, string $message, array $options = []): void
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
        $logger = new TestLogger();
        $escargot = $escargot->withLogger($logger);

        // Add subscribers
        $escargot->addSubscriber(new RobotsSubscriber());
        $escargot->addSubscriber(new HtmlCrawlerSubscriber());

        // We also add a subscriber that shall request the crawl URIs that match the BaseUriCollection.
        $indexerSubscriber = new SearchIndexSubscriber();
        $escargot->addSubscriber($indexerSubscriber);

        $escargot->crawl();

        $filteredLogs = $this->cleanLogs($logger);

        $this->assertSame($expectedLogs, $filteredLogs, $message);

        $filteredRequests = array_map(static fn (CrawlUri $crawlUri) => \sprintf('Successful request! %s.', (string) $crawlUri), $indexerSubscriber->getUris());

        $this->assertSame($expectedRequests, $filteredRequests, $message);
    }

    /**
     * @return iterable<array{\Closure(string, string, array<string, mixed>): MockResponse, array<string>, array<string>, string, array<string, string>}>
     */
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
        $subscriber = $this->createResponseFailureSubscriber($transportFailure);
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
            ->willReturnCallback(
                function (CrawlUri $crawlUri, HttpExceptionInterface $exception, ResponseInterface $response) use ($auxiliaryResponse): void {
                    $this->assertSame('https://example.com/crawled', (string) $crawlUri->getUri());
                    $this->assertSame($auxiliaryResponse, $exception->getResponse());
                    $this->assertSame('https://example.com/crawled', $response->getInfo('url'));
                    $this->assertSame('https://example.com/crawled', (string) $response->getInfo('user_data')->getUri());
                },
            )
        ;
        $escargot->addSubscriber($subscriber);

        $escargot->crawl();

        $this->assertSame(1, $escargot->getRequestsSent());
    }

    #[DataProvider('provideRedirectQueues')]
    public function testRedirectDestinationIsAvailableToSubscribersAndStored(QueueInterface $queue): void
    {
        $original = new Uri('https://example.com/original');
        $response = new MockResponse('Content', ['redirect_count' => 2, 'url' => 'https://example.com/final']);
        $escargot = Escargot::create(new BaseUriCollection([$original]), $queue)
            ->withHttpClient(new MockHttpClient($response))
        ;
        $escargot->addSubscriber($this->createRedirectSubscriber());

        $escargot->crawl();

        $stored = $queue->get($escargot->getJobId(), $original);
        $this->assertInstanceOf(CrawlUri::class, $stored);
        $this->assertRedirectedCrawlUri($stored);

        $all = iterator_to_array($queue->getAll($escargot->getJobId()));
        $this->assertCount(1, $all);
        $this->assertRedirectedCrawlUri($all[0]);
    }

    /**
     * @return iterable<array{QueueInterface}>
     */
    public static function provideRedirectQueues(): iterable
    {
        yield 'in memory' => [new InMemoryQueue()];
        yield 'doctrine' => [DoctrineQueueFactory::create()];
        yield 'lazy' => [new LazyQueue(new InMemoryQueue(), DoctrineQueueFactory::create())];
    }

    public function testRedirectDestinationIsAvailableForHttpExceptions(): void
    {
        $queue = DoctrineQueueFactory::create();
        $response = new MockResponse('Missing', ['http_code' => 404, 'redirect_count' => 1, 'url' => 'https://example.com/final']);
        $escargot = Escargot::create(new BaseUriCollection([new Uri('https://example.com/original')]), $queue)
            ->withHttpClient(new MockHttpClient($response))
        ;
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->once())
            ->method('onHttpException')
            ->willReturnCallback($this->assertRedirectedCrawlUri(...))
        ;
        $escargot->addSubscriber($subscriber);

        $escargot->crawl();

        $stored = $queue->get($escargot->getJobId(), new Uri('https://example.com/original'));
        $this->assertInstanceOf(CrawlUri::class, $stored);
        $this->assertRedirectedCrawlUri($stored);
    }

    #[DataProvider('provideRedirectQueues')]
    public function testSkippedRedirectDestinationIsStored(QueueInterface $queue): void
    {
        $final = new Uri('https://example.com/final');
        $original = new Uri('https://example.com/original');
        $response = new MockResponse('Duplicate', ['redirect_count' => 1, 'url' => (string) $final]);
        $escargot = Escargot::create(new BaseUriCollection([$final, $original]), $queue)
            ->withHttpClient(new MockHttpClient([new MockResponse('Content'), $response]))
            ->withConcurrency(1)
        ;
        $subscriber = $this->createMock(SubscriberInterface::class);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->once())
            ->method('needsContent')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->once())
            ->method('onLastChunk')
        ;
        $escargot->addSubscriber($subscriber);

        $escargot->crawl();

        $all = iterator_to_array($queue->getAll($escargot->getJobId()));
        $this->assertCount(2, $all);
        $this->assertRedirectedCrawlUri($all[1]);
    }

    public function testRelativeLinksUseRedirectDestination(): void
    {
        $requests = [];
        $client = $this->createRedirectedHtmlClient($requests);
        $escargot = Escargot::create(new BaseUriCollection([new Uri('https://example.com/original')]), new InMemoryQueue())
            ->withHttpClient($client)
        ;
        $subscriber = $this->createMock(SubscriberInterface::class);
        $subscriber
            ->expects($this->exactly(2))
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->method('needsContent')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;
        $escargot->addSubscriber($subscriber);
        $escargot->addSubscriber(new HtmlCrawlerSubscriber());

        $escargot->crawl();

        $this->assertSame(['https://example.com/original', 'https://example.com/new/child'], $requests);
    }

    /**
     * @return array<string>
     */
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

    /**
     * @param list<string> $requests
     */
    private function createRedirectedHtmlClient(array &$requests): MockHttpClient
    {
        return new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $url;

                if ('https://example.com/original' === $url) {
                    return new MockResponse('<a href="child">Child</a>', [
                        'redirect_count' => 1,
                        'url' => 'https://example.com/new/index',
                        'response_headers' => ['content-type: text/html'],
                    ]);
                }

                return new MockResponse('Done');
            },
        );
    }

    private function createRedirectSubscriber(): SubscriberInterface
    {
        $subscriber = $this->createMock(SubscriberInterface::class);
        $subscriber
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;
        $subscriber
            ->expects($this->once())
            ->method('needsContent')
            ->willReturnCallback(
                function (CrawlUri $crawlUri): string {
                    $this->assertRedirectedCrawlUri($crawlUri);

                    return SubscriberInterface::DECISION_POSITIVE;
                },
            )
        ;
        $subscriber
            ->expects($this->once())
            ->method('onLastChunk')
            ->willReturnCallback($this->assertRedirectedCrawlUri(...))
        ;

        return $subscriber;
    }

    private function assertRedirectedCrawlUri(CrawlUri $crawlUri): void
    {
        $this->assertSame('https://example.com/original', (string) $crawlUri->getUri());
        $this->assertSame('https://example.com/final', (string) $crawlUri->getRedirectedTo());
    }

    private function createResponseFailureSubscriber(bool $transportFailure): SubscriberInterface
    {
        /** @var SubscriberInterface&ExceptionSubscriberInterface&MockObject $subscriber */
        $subscriber = $this->createMockForIntersectionOfInterfaces([SubscriberInterface::class, ExceptionSubscriberInterface::class]);
        $subscriber
            ->expects($this->once())
            ->method('shouldRequest')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($transportFailure ? $this->once() : $this->never())
            ->method('needsContent')
            ->willReturn(SubscriberInterface::DECISION_POSITIVE)
        ;

        $subscriber
            ->expects($this->once())
            ->method($transportFailure ? 'onTransportException' : 'onHttpException')
            ->with(
                $this->isInstanceOf(CrawlUri::class),
                $this->isInstanceOf($transportFailure ? TransportExceptionInterface::class : HttpExceptionInterface::class),
            )
            ->willReturnCallback(
                function (CrawlUri $crawlUri, ExceptionInterface $exception, ResponseInterface $response): void {
                    $this->assertSame('https://example.com/', $response->getInfo('url'));
                    $this->assertFalse($response->getInfo('canceled'));
                },
            )
        ;

        $subscriber
            ->expects($this->never())
            ->method($transportFailure ? 'onHttpException' : 'onTransportException')
        ;

        return $subscriber;
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
            ->willReturnCallback(
                function (CrawlUri $crawlUri, TransportExceptionInterface $actualException, ResponseInterface|null $response) use ($exception): void {
                    $this->assertSame('https://example.com/failure', (string) $crawlUri->getUri());
                    $this->assertSame($exception, $actualException);
                    $this->assertNull($response);
                },
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
