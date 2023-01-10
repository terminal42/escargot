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

use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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

    /**
     * @var QueueInterface
     */
    private $queue;

    /**
     * @var string
     */
    private $jobId;

    /**
     * @var BaseUriCollection
     */
    private $baseUris;

    /**
     * @var HttpClientInterface|null
     */
    private $client;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    /**
     * @var SubscriberInterface[]
     */
    private $subscribers = [];

    /**
     * @var string
     */
    private $userAgent;

    /**
     * Maximum number of requests
     * Escargot is going to
     * execute.
     * 0 means no limit.
     *
     * @var int
     */
    private $maxRequests = 0;

    /**
     * Request delay in microseconds.
     * 0 means no delay.
     *
     * @var int
     */
    private $requestDelay = 0;

    /**
     * Maximum concurrent requests
     * that are being sent.
     *
     * @var int
     */
    private $concurrency = 10;

    /**
     * Maximum depth Escargot
     * is going to crawl.
     * 0 means no limit.
     *
     * @var int
     */
    private $maxDepth = 0;

    /**
     * @var int
     */
    private $requestsSent = 0;

    /**
     * @var array
     */
    private $runningRequests = [];

    /**
     * Keeps track of all the decisions
     * for all the subscribers for
     * every CrawlUri instance.
     *
     * @var array
     */
    private $decisionMap = ['shouldRequest' => [], 'needsContent' => []];

    private function __construct(QueueInterface $queue, string $jobId, BaseUriCollection $baseUris)
    {
        $this->queue = $queue;
        $this->jobId = $jobId;
        $this->baseUris = $baseUris;

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
     * @return SubscriberInterface[]
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

    public function getLogger(): ?LoggerInterface
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
            $queue->getBaseUris($jobId)
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
            $baseUris
        );
    }

    public function crawl(): void
    {
        while (true) {
            $responses = $this->prepareResponses();

            if (0 === \count($this->runningRequests) && 0 === \count($responses)) {
                break;
            }

            $this->processResponses($responses);
        }

        $this->log(
            LogLevel::DEBUG,
            sprintf('Finished crawling! Sent %d request(s).', $this->getRequestsSent())
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

    public function getCrawlUri(UriInterface $uri): ?CrawlUri
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
            $logger = new SubscriberLogger($this->logger, \get_class($subscriber));
            $subscriber->setLogger($logger);
        }
    }

    /**
     * Logs a message to the logger if one was provided.
     */
    private function log(string $level, string $message, CrawlUri $crawlUri = null): void
    {
        if (null === $this->logger) {
            return;
        }

        $context = ['source' => static::class];

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
                if ($response->getInfo('redirect_count') > 0
                    && null !== $this->queue->get($this->getJobId(), CrawlUri::normalizeUri(HttpUriFactory::create((string) $response->getInfo('url'))))
                ) {
                    $this->log(
                        LogLevel::DEBUG,
                        'Skipped further response processing because crawler got redirected to an URI that\'s already been crawled.',
                        $crawlUri
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
        $responses = [];

        while (!$this->isMaxConcurrencyReached()
            && !$this->isMaxRequestsReached()
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
                    $crawlUri
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
                usleep($this->requestDelay);
            }

            try {
                $response = $this->getClient()->request('GET', (string) $crawlUri->getUri(), [
                    'user_data' => $crawlUri,
                ]);
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
        $this->decisionMap[$key][(string) $crawlUri->getUri().\get_class($subscriber)] = $decision;
    }

    private function getDecisionForSubscriber(string $key, CrawlUri $crawlUri, SubscriberInterface $subscriber): string
    {
        return $this->decisionMap[$key][(string) $crawlUri->getUri().\get_class($subscriber)] ?? SubscriberInterface::DECISION_ABSTAIN;
    }

    private function isMaxRequestsReached(): bool
    {
        return 0 !== $this->maxRequests && $this->requestsSent >= $this->maxRequests;
    }

    private function isMaxConcurrencyReached(): bool
    {
        return \count($this->runningRequests) >= $this->concurrency;
    }

    private function handleException(ExceptionInterface $exception, CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk = null): void
    {
        // Log the exception
        $this->log(
            LogLevel::DEBUG,
            sprintf('Exception of type "%s" occurred: %s', \get_class($exception), $exception->getMessage()),
            $crawlUri
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

                switch (true) {
                    case $exception instanceof TransportExceptionInterface:
                        $subscriber->onTransportException($crawlUri, $exception, $response);
                        break;
                    case $exception instanceof HttpExceptionInterface:
                        $subscriber->onHttpException($crawlUri, $exception, $response, $chunk);
                        break;
                    default:
                        throw new \RuntimeException('Unknown exception type!');
                }
            }
        }

        // Make sure the response is canceled (after all the subscribers are called as they would otherwise get the
        // canceled information rather than the original exception)
        $response->cancel();
    }
}
