<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\Exception\ClientAlreadyCustomizedException;
use Terminal42\Escargot\Exception\InvalidJobIdException;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Subscriber\ExceptionSubscriberInterface;
use Terminal42\Escargot\Subscriber\FinishedCrawlingSubscriberInterface;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\Subscriber\TagValueResolvingSubscriberInterface;

final class Escargot
{
    private const DEFAULT_USER_AGENT = 'terminal42/escargot';

    private ClockInterface $clock;

    private HttpClientInterface|null $client = null;

    private LoggerInterface|null $logger = null;

    /**
     * @var array<SubscriberInterface>
     */
    private array $subscribers = [];

    private string $userAgent;

    /**
     * Maximum number of requests
     * Escargot is going to
     * execute.
     * 0 means no limit.
     */
    private int $maxRequests = 0;

    /**
     * Maximum number of duration in seconds
     * Escargot is going to work on requests.
     *
     * 0 means no limit.
     */
    private int $maxDurationInSeconds = 0;

    /**
     * Request delay in microseconds.
     * 0 means no delay.
     */
    private int $requestDelay = 0;

    /**
     * Maximum concurrent requests
     * that are being sent.
     */
    private int $concurrency = 10;

    /**
     * Maximum depth Escargot
     * is going to crawl.
     * 0 means no limit.
     */
    private int $maxDepth = 0;

    private int $requestsSent = 0;

    private array $runningRequests = [];

    /**
     * Keeps track of all the decisions
     * for all the subscribers for
     * every CrawlUri instance.
     */
    private array $decisionMap = ['shouldRequest' => [], 'needsContent' => []];

    private \DateTimeImmutable $startTime;

    private function __construct(
        private readonly QueueInterface $queue,
        private readonly string $jobId,
        private readonly BaseUriCollection $baseUris,
    ) {
        $this->clock = new NativeClock();
        $this->userAgent = self::DEFAULT_USER_AGENT;
    }

    public function __clone()
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof EscargotAwareInterface) {
                $subscriber->setEscargot($this);
            }
        }
    }

    public function withHttpClient(HttpClientInterface $client): self
    {
        $new = clone $this;
        $new->client = $client;

        return $new;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @return array<SubscriberInterface>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /**
     * @throws ClientAlreadyCustomizedException if you have already used Escargot::withHttpClient() before
     */
    public function withUserAgent(string $userAgent): self
    {
        if (null !== $this->client) {
            throw new ClientAlreadyCustomizedException('Cannot override user agent, as you have already customized the client.');
        }

        $new = clone $this;
        $new->userAgent = $userAgent;

        return $new;
    }

    public function withMaxRequests(int $maxRequests): self
    {
        $new = clone $this;
        $new->maxRequests = $maxRequests;

        return $new;
    }

    public function withMaxDurationInSeconds(int $maxDurationInSeconds): self
    {
        $new = clone $this;
        $new->maxDurationInSeconds = $maxDurationInSeconds;

        return $new;
    }

    public function withClock(ClockInterface $clock): self
    {
        $new = clone $this;
        $new->clock = $clock;

        return $new;
    }

    public function withConcurrency(int $concurrency): self
    {
        $new = clone $this;
        $new->concurrency = $concurrency;

        return $new;
    }

    public function getRequestDelay(): int
    {
        return $this->requestDelay;
    }

    public function withRequestDelay(int $requestDelay): self
    {
        $new = clone $this;
        $new->requestDelay = $requestDelay;

        return $new;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function withMaxDepth(int $maxDepth): self
    {
        $new = clone $this;
        $new->maxDepth = $maxDepth;

        return $new;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $new = clone $this;
        $new->logger = $logger;

        foreach ($this->subscribers as $subscriber) {
            $new->setLoggerToSubscriber($subscriber);
        }

        return $new;
    }

    public function addSubscriber(SubscriberInterface $subscriber): self
    {
        if ($subscriber instanceof EscargotAwareInterface) {
            $subscriber->setEscargot($this);
        }

        $this->setLoggerToSubscriber($subscriber);

        $this->subscribers[] = $subscriber;

        return $this;
    }

    public function getLogger(): LoggerInterface|null
    {
        return $this->logger;
    }

    public function getClient(): HttpClientInterface
    {
        if (null === $this->client) {
            $this->client = HttpClient::create(['headers' => ['User-Agent' => $this->getUserAgent()]]);
        }

        return $this->client;
    }

    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getBaseUris(): BaseUriCollection
    {
        return $this->baseUris;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    public function getRequestsSent(): int
    {
        return $this->requestsSent;
    }

    public static function createFromJobId(string $jobId, QueueInterface $queue): self
    {
        if (!$queue->isJobIdValid($jobId)) {
            throw new InvalidJobIdException(sprintf('Job ID "%s" is invalid!', $jobId));
        }

        return new self(
            $queue,
            $jobId,
            $queue->getBaseUris($jobId),
        );
    }

    public static function create(BaseUriCollection $baseUris, QueueInterface $queue): self
    {
        if (0 === \count($baseUris)) {
            throw new InvalidJobIdException('Cannot create an Escargot instance with an empty BaseUriCollection!');
        }

        $jobId = $queue->createJobId($baseUris);

        return new self(
            $queue,
            $jobId,
            $baseUris,
        );
    }

    public function crawl(): void
    {
        $this->startTime = $this->clock->now();

        while (true) {
            $responses = $this->prepareResponses();

            if (0 === \count($this->runningRequests) && 0 === \count($responses)) {
                break;
            }

            $this->processResponses($responses);
        }

        $this->log(
            LogLevel::DEBUG,
            sprintf('Finished crawling! Sent %d request(s).', $this->getRequestsSent()),
        );

        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof FinishedCrawlingSubscriberInterface) {
                $subscriber->finishedCrawling();
            }
        }
    }

    /**
     * Adds an URI to the queue if not present already.
     *
     * You are expected to handle max depth yourself. This is by design as you might not even need to do certain
     * things before calling Escargot::addUriToQueue(). E.g. you don't need to parse any HTML if all the links found
     * on this document would be ignored here anyway.
     * You can use Escargot::isMaxDepthReached() for that.
     *
     * @return CrawlUri the new CrawlUri instance
     *
     * @throw \BadMethodCallException If max depth would be reached.
     */
    public function addUriToQueue(UriInterface $uri, CrawlUri $foundOn, bool $processed = false): CrawlUri
    {
        if ($this->isMaxDepthReached($foundOn)) {
            throw new \BadMethodCallException('Max depth configured is reached, you cannot add this URI.');
        }

        $crawlUri = $this->getCrawlUri($uri);
        if (null === $crawlUri) {
            $crawlUri = new CrawlUri($uri, $foundOn->getLevel() + 1, $processed, $foundOn->getUri());
            $this->queue->add($this->jobId, $crawlUri);
        }

        return $crawlUri;
    }

    public function isMaxDepthReached(CrawlUri $foundOn): bool
    {
        if (0 === $this->getMaxDepth()) {
            return false;
        }

        return $foundOn->getLevel() >= $this->getMaxDepth();
    }

    public function getCrawlUri(UriInterface $uri): CrawlUri|null
    {
        return $this->queue->get($this->jobId, $uri);
    }

    /**
     * @return mixed|null
     */
    public function resolveTagValue(string $tag)
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof TagValueResolvingSubscriberInterface) {
                if (null !== ($value = $subscriber->resolveTagValue($tag))) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function setLoggerToSubscriber(SubscriberInterface $subscriber): void
    {
        if (null !== $this->logger && $subscriber instanceof LoggerAwareInterface) {
            // Decorate logger to automatically pass the subscriber in the logging context
            $logger = new SubscriberLogger($this->logger, $subscriber::class);
            $subscriber->setLogger($logger);
        }
    }

    /**
     * Logs a message to the logger if one was provided.
     */
    private function log(string $level, string $message, CrawlUri|null $crawlUri = null): void
    {
        if (null === $this->logger) {
            return;
        }

        $context = ['source' => self::class];

        if (null !== $crawlUri) {
            $context['crawlUri'] = $crawlUri;
        }

        $this->logger->log($level, $message, $context);
    }

    private function startRequest(ResponseInterface $response): void
    {
        $uri = $this->getUriFromResponse($response);

        if (!isset($this->runningRequests[$uri])) {
            ++$this->requestsSent;
        }

        $this->runningRequests[$uri] = true;
    }

    private function finishRequest(ResponseInterface $response): void
    {
        $uri = $this->getUriFromResponse($response);

        unset($this->runningRequests[$uri]);
    }

    private function getUriFromResponse(ResponseInterface $response): string
    {
        return (string) $response->getInfo('user_data')->getUri();
    }

    /**
     * @param array<ResponseInterface> $responses
     */
    private function processResponses(array $responses): void
    {
        foreach ($this->getClient()->stream($responses) as $response => $chunk) {
            $this->processResponseChunk($response, $chunk);
        }
    }

    private function processResponseChunk(ResponseInterface $response, ChunkInterface $chunk): void
    {
        /** @var CrawlUri $crawlUri */
        $crawlUri = $response->getInfo('user_data');

        try {
            if ($chunk->isFirst()) {
                // If the response was a redirect of an URI we have already crawled, we can early abort
                // this response as it has already been processed.
                if (
                    $response->getInfo('redirect_count') > 0
                    && null !== $this->queue->get($this->getJobId(), CrawlUri::normalizeUri(HttpUriFactory::create((string) $response->getInfo('url'))))
                ) {
                    $this->log(
                        LogLevel::DEBUG,
                        'Skipped further response processing because crawler got redirected to an URI that\'s already been crawled.',
                        $crawlUri,
                    );
                    $response->cancel();
                    $this->finishRequest($response);

                    return;
                }

                // Makes sure an HttpException is thrown, no matter what the subscribers do to have a consistent
                // behaviour. Otherwise whether or not the onHttpException() method would be called on the subscribers
                // would depend on the fact if all subscribers check for the status code or not.
                $response->getHeaders();

                $needsContent = false;

                foreach ($this->subscribers as $subscriber) {
                    $shouldRequestDecision = $this->getDecisionForSubscriber('shouldRequest', $crawlUri, $subscriber);
                    if (SubscriberInterface::DECISION_NEGATIVE === $shouldRequestDecision) {
                        continue;
                    }

                    $needsContentDecision = $subscriber->needsContent($crawlUri, $response, $chunk);
                    $this->storeDecisionForSubscriber('needsContent', $crawlUri, $subscriber, $needsContentDecision);

                    if (SubscriberInterface::DECISION_POSITIVE === $needsContentDecision) {
                        $needsContent = true;
                    }
                }

                if (!$needsContent) {
                    $response->cancel();
                    $this->finishRequest($response);
                }
            }

            if ($chunk->isLast()) {
                foreach ($this->subscribers as $subscriber) {
                    $needsContentDecision = $this->getDecisionForSubscriber('needsContent', $crawlUri, $subscriber);

                    if (SubscriberInterface::DECISION_NEGATIVE !== $needsContentDecision) {
                        $subscriber->onLastChunk($crawlUri, $response, $chunk);
                    }
                }
                $this->finishRequest($response);
            }
        } catch (ExceptionInterface $exception) {
            $this->handleException($exception, $crawlUri, $response, $chunk);
        }
    }

    /**
     * @return array<ResponseInterface>
     */
    private function prepareResponses(): array
    {
        $response = null;
        $responses = [];

        $hasMaxRequestsReached = $this->isMaxRequestsReached();
        $hasMaxDurationReached = $this->isMaxDurationInSecondsReached();

        if ($hasMaxRequestsReached) {
            $this->log(LogLevel::DEBUG, 'Configured max requests reached!');
        }

        if ($hasMaxDurationReached) {
            $this->log(LogLevel::DEBUG, 'Configured max duration reached!');
        }

        while (!$this->isMaxConcurrencyReached()
            && !$hasMaxRequestsReached
            && !$hasMaxDurationReached
            && ($crawlUri = $this->queue->getNext($this->jobId))
        ) {
            // Already processed, ignore
            if ($crawlUri->isProcessed()) {
                continue;
            }

            // Otherwise mark as processed
            $crawlUri->markProcessed();
            $this->queue->add($this->jobId, $crawlUri);

            // Skip non http URIs
            if (!\in_array($crawlUri->getUri()->getScheme(), ['http', 'https'], true)) {
                $this->log(
                    LogLevel::DEBUG,
                    'Skipped because it\'s not a valid http(s) URI.',
                    $crawlUri,
                );
                continue;
            }

            // Check if any subscriber wants this crawlUri to be requested
            $shouldRequest = false;

            foreach ($this->subscribers as $subscriber) {
                $decision = $subscriber->shouldRequest($crawlUri);
                $this->storeDecisionForSubscriber('shouldRequest', $crawlUri, $subscriber, $decision);
                if (SubscriberInterface::DECISION_POSITIVE === $decision) {
                    $shouldRequest = true;
                }
            }

            // No subscriber wanted the URI to be requested
            if (!$shouldRequest) {
                continue;
            }

            // Request delay
            if (0 !== $this->requestDelay) {
                $this->clock->sleep($this->requestDelay / 1_000_000);
            }

            try {
                $response = $this->getClient()->request(
                    'GET',
                    (string) $crawlUri->getUri(),
                    [
                        'user_data' => $crawlUri,
                    ],
                );
                $responses[] = $response;

                // Mark the response as started
                $this->startRequest($response);
            } catch (TransportExceptionInterface $exception) {
                $this->handleException($exception, $crawlUri, $response);
            }
        }

        return $responses;
    }

    private function storeDecisionForSubscriber(string $key, CrawlUri $crawlUri, SubscriberInterface $subscriber, string $decision): void
    {
        $this->decisionMap[$key][(string) $crawlUri->getUri().$subscriber::class] = $decision;
    }

    private function getDecisionForSubscriber(string $key, CrawlUri $crawlUri, SubscriberInterface $subscriber): string
    {
        return $this->decisionMap[$key][(string) $crawlUri->getUri().$subscriber::class] ?? SubscriberInterface::DECISION_ABSTAIN;
    }

    private function isMaxRequestsReached(): bool
    {
        return 0 !== $this->maxRequests && $this->requestsSent >= $this->maxRequests;
    }

    private function isMaxDurationInSecondsReached(): bool
    {
        if (0 === $this->maxDurationInSeconds) {
            return false;
        }

        return $this->clock->now() >= $this->startTime->add(new \DateInterval('PT'.$this->maxDurationInSeconds.'S'));
    }

    private function isMaxConcurrencyReached(): bool
    {
        return \count($this->runningRequests) >= $this->concurrency;
    }

    private function handleException(ExceptionInterface $exception, CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface|null $chunk = null): void
    {
        // Log the exception
        $this->log(
            LogLevel::DEBUG,
            sprintf('Exception of type "%s" occurred: %s', $exception::class, $exception->getMessage()),
            $crawlUri,
        );

        // Mark the responses as finished
        $this->finishRequest($response);

        // Call the subscribers
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof ExceptionSubscriberInterface) {
                $requestDecisions = [];
                $requestDecisions[] = $this->getDecisionForSubscriber('shouldRequest', $crawlUri, $subscriber);
                $requestDecisions[] = $this->getDecisionForSubscriber('needsContent', $crawlUri, $subscriber);

                // If the subscriber did not initiate the request, it also doesn't need the exception
                if (\in_array(SubscriberInterface::DECISION_NEGATIVE, $requestDecisions, true)) {
                    continue;
                }

                match (true) {
                    $exception instanceof TransportExceptionInterface => $subscriber->onTransportException($crawlUri, $exception, $response),
                    $exception instanceof HttpExceptionInterface => $subscriber->onHttpException($crawlUri, $exception, $response, $chunk),
                    default => throw new \RuntimeException('Unknown exception type!'),
                };
            }
        }

        // Make sure the response is canceled (after all the subscribers are called as they would otherwise get the
        // canceled information rather than the original exception)
        $response->cancel();
    }
}
