<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\Exception\InvalidJobIdException;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Subscriber\ExceptionSubscriberInterface;
use Terminal42\Escargot\Subscriber\FinishedCrawlingSubscriberInterface;
use Terminal42\Escargot\Subscriber\SubscriberInterface;

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

    private function __construct(QueueInterface $queue, string $jobId, BaseUriCollection $baseUris, ?HttpClientInterface $client = null)
    {
        $this->client = $client;
        $this->queue = $queue;
        $this->jobId = $jobId;
        $this->baseUris = $baseUris;

        $this->userAgent = self::DEFAULT_USER_AGENT;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function withUserAgent(string $userAgent): self
    {
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

        return $new;
    }

    public function addSubscriber(SubscriberInterface $subscriber): self
    {
        if ($subscriber instanceof EscargotAwareInterface) {
            $subscriber->setEscargot($this);
        }

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

    public static function createFromJobId(string $jobId, QueueInterface $queue, ?HttpClientInterface $client = null): self
    {
        if (!$queue->isJobIdValid($jobId)) {
            throw new InvalidJobIdException(sprintf('Job ID "%s" is invalid!', $jobId));
        }

        return new self(
            $queue,
            $jobId,
            $queue->getBaseUris($jobId),
            $client
        );
    }

    public static function create(BaseUriCollection $baseUris, QueueInterface $queue, ?HttpClientInterface $client = null): self
    {
        if (0 === \count($baseUris)) {
            throw new InvalidJobIdException('Cannot create an Escargot instance with an empty BaseUriCollection!');
        }

        $jobId = $queue->createJobId($baseUris);

        return new self(
            $queue,
            $jobId,
            $baseUris,
            $client
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

        $this->log(LogLevel::DEBUG, sprintf('Finished crawling! Sent %d request(s).', $this->getRequestsSent()));

        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof FinishedCrawlingSubscriberInterface) {
                $subscriber->finishedCrawling();
            }
        }
    }

    /**
     * Adds an URI to the queue if not present already.
     *
     * @return CrawlUri The new CrawlUri instance
     */
    public function addUriToQueue(UriInterface $uri, CrawlUri $foundOn, bool $processed = false): CrawlUri
    {
        $crawlUri = $this->getCrawlUri($uri);
        if (null === $crawlUri) {
            $crawlUri = new CrawlUri($uri, $foundOn->getLevel() + 1, $processed, $foundOn->getUri());
            $this->queue->add($this->jobId, $crawlUri);
        }

        return $crawlUri;
    }

    public function getCrawlUri(UriInterface $uri): ?CrawlUri
    {
        return $this->queue->get($this->jobId, $uri);
    }

    /**
     * Logs a message to the logger if one was provided.
     *
     * @param array<string,array|string|int> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (null === $this->logger) {
            return;
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
        // Mark all responses before we start to stream, otherwise the internal counter is only
        // updated once the first chunk is arrived which might be too late as in between there
        // might be already new requests being created.
        foreach ($responses as $response) {
            $this->startRequest($response);
        }

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
                $needsContent = SubscriberInterface::DECISION_ABSTAIN;
                foreach ($this->subscribers as $subscriber) {
                    $needsContent = $subscriber->needsContent($crawlUri, $response, $chunk, $needsContent);
                }

                if (SubscriberInterface::DECISION_POSITIVE !== $needsContent) {
                    $response->cancel();
                    $this->finishRequest($response);
                }
            }

            if ($chunk->isLast()) {
                foreach ($this->subscribers as $subscriber) {
                    $subscriber->onLastChunk($crawlUri, $response, $chunk);
                }
                $this->finishRequest($response);
            }
        } catch (TransportExceptionInterface | RedirectionExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            foreach ($this->subscribers as $subscriber) {
                if ($subscriber instanceof ExceptionSubscriberInterface) {
                    $subscriber->onException($exception, $response);
                }
            }
            $this->finishRequest($response);
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
                    $crawlUri->createLogMessage('Skipped because it\'s not a valid http(s) URI.')
                );
                continue;
            }

            // Stop crawling if we have reached max depth
            if (0 !== $this->maxDepth && $this->maxDepth <= $crawlUri->getLevel()) {
                $this->log(
                    LogLevel::DEBUG,
                    $crawlUri->createLogMessage('Will not crawl as max depth is reached!')
                );
                continue;
            }

            // Check if any subscriber wants this crawlUri to be requested
            $doRequest = SubscriberInterface::DECISION_ABSTAIN;

            foreach ($this->subscribers as $subscriber) {
                $doRequest = $subscriber->shouldRequest($crawlUri, $doRequest);
            }

            // No subscriber wanted the URI to be requested
            if (SubscriberInterface::DECISION_POSITIVE !== $doRequest) {
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
            } catch (TransportExceptionInterface $exception) {
                foreach ($this->subscribers as $subscriber) {
                    if ($subscriber instanceof ExceptionSubscriberInterface) {
                        $subscriber->onException($exception, $response);
                    }
                }
            }
        }

        return $responses;
    }

    private function isMaxRequestsReached(): bool
    {
        return 0 !== $this->maxRequests && $this->requestsSent >= $this->maxRequests;
    }

    private function isMaxConcurrencyReached(): bool
    {
        return \count($this->runningRequests) >= $this->concurrency;
    }
}
