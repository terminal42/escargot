<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Queue;

use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\CrawlUri;

class InMemoryQueue implements QueueInterface
{
    /**
     * @var array<string,array<UriInterface>>
     */
    private $baseUris = [];

    /**
     * @var array<string,array<string,CrawlUri>>
     */
    private $queue = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private $processed = [];

    public function createJobId(UriInterface $baseUri): string
    {
        $jobId = bin2hex(random_bytes(32));

        $this->baseUris[$jobId] = $baseUri;

        $this->add($jobId, new CrawlUri($baseUri, 0));

        return $jobId;
    }

    public function isJobIdValid(string $jobId): bool
    {
        return isset($this->baseUris[$jobId]);
    }

    public function deleteJobId(string $jobId): void
    {
        unset($this->baseUris[$jobId], $this->queue[$jobId], $this->processed[$jobId]);
    }

    public function getBaseUri(string $jobId): UriInterface
    {
        return $this->baseUris[$jobId];
    }

    public function has(string $jobId, CrawlUri $crawlUri): bool
    {
        return isset($this->queue[$jobId][(string) $crawlUri->getUri()]);
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        if (!isset($this->queue[$jobId])) {
            $this->queue[$jobId] = [];
        }

        $this->queue[$jobId][(string) $crawlUri->getUri()] = $crawlUri;
        $this->processed[$jobId][(string) $crawlUri->getUri()] = false;
    }

    public function markProcessed(string $jobId, CrawlUri $crawlUri): void
    {
        $this->processed[$jobId][(string) $crawlUri->getUri()] = true;
    }

    public function isProcessed(string $jobId, CrawlUri $crawlUri): bool
    {
        return isset($this->processed[$jobId][(string) $crawlUri->getUri()])
            && true === $this->processed[$jobId][(string) $crawlUri->getUri()];
    }

    public function hasPending(string $jobId): bool
    {
        return \in_array(false, $this->processed[$jobId], true);
    }

    public function getNext(string $jobId): ?CrawlUri
    {
        if (!isset($this->queue[$jobId])) {
            return null;
        }

        foreach ($this->processed[$jobId] as $uri => $processed) {
            if (!$processed) {
                return $this->queue[$jobId][$uri];
            }
        }

        return null;
    }

    public function countAll(string $jobId): int
    {
        return \count($this->queue[$jobId]);
    }

    public function countPending(string $jobId): int
    {
        $count = 0;

        foreach ($this->processed[$jobId] as $uri => $processed) {
            if (!$processed) {
                ++$count;
            }
        }

        return $count;
    }

    public function getAll(string $jobId): \Generator
    {
        foreach ($this->queue[$jobId] as $uri => $crawlUri) {
            yield $crawlUri;
        }
    }
}
