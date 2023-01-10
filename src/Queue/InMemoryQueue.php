<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Queue;

use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;

final class InMemoryQueue implements QueueInterface
{
    /**
     * @var array<string,array<UriInterface>>
     */
    private $baseUris = [];

    /**
     * @var array<string,array<string,CrawlUri>>
     */
    private $queue = [];

    public function createJobId(BaseUriCollection $baseUris): string
    {
        $jobId = bin2hex(random_bytes(32));

        $this->queue[$jobId] = [];
        $this->baseUris[$jobId] = $baseUris;

        foreach ($baseUris as $baseUri) {
            $this->add($jobId, new CrawlUri($baseUri, 0));
        }

        return $jobId;
    }

    public function isJobIdValid(string $jobId): bool
    {
        return isset($this->baseUris[$jobId]);
    }

    public function deleteJobId(string $jobId): void
    {
        unset($this->baseUris[$jobId], $this->queue[$jobId]);
    }

    public function getBaseUris(string $jobId): BaseUriCollection
    {
        return $this->baseUris[$jobId];
    }

    public function get(string $jobId, UriInterface $uri): ?CrawlUri
    {
        return $this->queue[$jobId][(string) $uri] ?? null;
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        if (!isset($this->queue[$jobId])) {
            $this->queue[$jobId] = [];
        }

        $this->queue[$jobId][(string) $crawlUri->getUri()] = $crawlUri;
    }

    public function getNext(string $jobId, int $skip = 0): ?CrawlUri
    {
        if (!isset($this->queue[$jobId])) {
            return null;
        }

        $i = 0;
        foreach ($this->queue[$jobId] as $crawlUri) {
            if ($crawlUri->isProcessed()) {
                continue;
            }

            if ($skip > 0 && $i < $skip) {
                ++$i;
                continue;
            }

            return $crawlUri;
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

        foreach ($this->queue[$jobId] as $crawlUri) {
            if (!$crawlUri->isProcessed()) {
                ++$count;
            }
        }

        return $count;
    }

    public function getAll(string $jobId): \Generator
    {
        foreach ($this->queue[$jobId] as $crawlUri) {
            yield $crawlUri;
        }
    }
}
