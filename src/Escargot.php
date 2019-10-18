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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Terminal42\Escargot\Event\FinishedCrawlingEvent;
use Terminal42\Escargot\Event\PreRequestEvent;
use Terminal42\Escargot\Event\RequestExceptionEvent;
use Terminal42\Escargot\Event\ResponseEvent;
use Terminal42\Escargot\Exception\InvalidJobIdException;
use Terminal42\Escargot\Queue\QueueInterface;

final class Escargot implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
     * @var EventDispatcherInterface|null
     */
    private $eventDispatcher;

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
     * @var int
     */
    private $requestsSent = 0;

    /**
     * @var int
     */
    private $runningRequests = 0;

    private function __construct(QueueInterface $queue, string $jobId, BaseUriCollection $baseUris, HttpClientInterface $client = null)
    {
        $this->client = $client;
        $this->queue = $queue;
        $this->jobId = $jobId;
        $this->baseUris = $baseUris;

        $this->setUserAgent(self::DEFAULT_USER_AGENT);
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        if (null === $this->eventDispatcher) {
            $this->eventDispatcher = new EventDispatcher();
        }

        return $this->eventDispatcher;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function setMaxRequests(int $maxRequests): void
    {
        $this->maxRequests = $maxRequests;
    }

    public function setConcurrency(int $concurrency): void
    {
        $this->concurrency = $concurrency;
    }

    public function getRequestDelay(): int
    {
        return $this->requestDelay;
    }

    public function setRequestDelay(int $requestDelay): self
    {
        $this->requestDelay = $requestDelay;

        return $this;
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): self
    {
        $this->getEventDispatcher()->addSubscriber($subscriber);

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

    /**
     * @throws InvalidJobIdException if the provided job ID could not be retrieved by the queue
     */
    public static function createFromJobId(string $jobId, QueueInterface $queue, HttpClientInterface $client = null): self
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

    public static function create(BaseUriCollection $baseUris, QueueInterface $queue, HttpClientInterface $client = null): self
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
        // We're finished if we have reached the max requests or the queue is empty
        // and no request is being processed anymore
        if (0 === $this->runningRequests
            && ($this->isMaxRequestsReached() || null === $this->queue->getNext($this->jobId))
        ) {
            $this->log(LogLevel::DEBUG, sprintf('Finished crawling! Sent %d request(s).', $this->getRequestsSent()));

            $this->getEventDispatcher()->dispatch(new FinishedCrawlingEvent($this));

            return;
        }

        $this->processResponses($this->prepareResponses());
    }

    /**
     * Adds an URI to the queue if not present already.
     *
     * @return bool True if it was added and false if it existed already before
     */
    public function addUriToQueue(UriInterface $uri, CrawlUri $foundOn, bool $processed = false): bool
    {
        $crawlUrl = $this->queue->get($this->jobId, $uri);
        if (null === $crawlUrl) {
            $crawlUrl = new CrawlUri($uri, $foundOn->getLevel() + 1, $processed, $foundOn->getUri());
            $this->queue->add($this->jobId, $crawlUrl);

            return true;
        }

        return false;
    }

    /**
     * Logs a message to the logger if one was provided.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (null === $this->logger) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }

    private function processResponses(array $responses): void
    {
        foreach ($this->getClient()->stream($responses) as $response => $chunk) {
            try {
                // Dispatch event
                $event = new ResponseEvent($this, $response, $chunk);
                $this->getEventDispatcher()->dispatch($event);

                // Response was canceled by listener
                if ($event->responseWasCanceled()) {
                    --$this->runningRequests;
                    continue;
                }

                if ($chunk->isLast()) {
                    --$this->runningRequests;
                }
            } catch (TransportExceptionInterface | RedirectionExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $e) {
                --$this->runningRequests;
                $this->getEventDispatcher()->dispatch(new RequestExceptionEvent($this, $e, $response));
            }
        }

        // Continue crawling
        $this->crawl();
    }

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

            // Dispatch event
            $event = new PreRequestEvent($this, $crawlUri);
            $this->getEventDispatcher()->dispatch($event);

            // A subscriber said this crawlUri shall not be requested
            if ($event->wasRequestAborted()) {
                continue;
            }

            // Request delay
            if (0 !== $this->requestDelay) {
                usleep($this->requestDelay);
            }

            try {
                $responses[] = $this->getClient()->request('GET', (string) $crawlUri->getUri(), [
                    'user_data' => $crawlUri,
                ]);
                ++$this->runningRequests;
                ++$this->requestsSent;
            } catch (TransportExceptionInterface $e) {
                --$this->runningRequests;

                $this->getEventDispatcher()->dispatch(new RequestExceptionEvent($this, $e));
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
        return $this->runningRequests >= $this->concurrency;
    }
}
