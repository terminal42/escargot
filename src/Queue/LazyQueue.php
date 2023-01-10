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

final class LazyQueue implements QueueInterface
{
    /**
     * @var QueueInterface
     */
    private $primaryQueue;

    /**
     * @var QueueInterface
     */
    private $secondaryQueue;

    /**
     * @var array<string,string>
     */
    private $jobIdMapper = [];

    /**
     * @var int
     */
    private $toSkip = 0;

    public function __construct(QueueInterface $primaryQueue, QueueInterface $secondaryQueue)
    {
        $this->primaryQueue = $primaryQueue;
        $this->secondaryQueue = $secondaryQueue;
    }

    public function createJobId(BaseUriCollection $baseUris): string
    {
        // The job id must be taken from the secondary queue
        return $this->secondaryQueue->createJobId($baseUris);
    }

    public function isJobIdValid(string $jobId): bool
    {
        // The job id must be valid in the secondary queue
        return $this->secondaryQueue->isJobIdValid($jobId);
    }

    public function deleteJobId(string $jobId): void
    {
        // We delete in both queues
        $this->secondaryQueue->deleteJobId($jobId);
        $this->primaryQueue->deleteJobId($this->getJobIdFromSecondaryJobId($jobId));
    }

    public function getBaseUris(string $jobId): BaseUriCollection
    {
        // Must be present in our primary queue as well
        return $this->primaryQueue->getBaseUris($this->getJobIdFromSecondaryJobId($jobId));
    }

    public function get(string $jobId, UriInterface $uri): ?CrawlUri
    {
        // If we have it in the primary queue, early return
        $crawlUri = $this->primaryQueue->get($this->getJobIdFromSecondaryJobId($jobId), $uri);

        if (null !== $crawlUri) {
            return $crawlUri;
        }

        // Otherwise we check in the secondary and add it to our primary queue for consecutive calls
        $crawlUri = $this->secondaryQueue->get($jobId, $uri);

        if (null !== $crawlUri) {
            $this->primaryQueue->add($this->getJobIdFromSecondaryJobId($jobId), $crawlUri);

            return $crawlUri;
        }

        return null;
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        // We only add in primary and mark the URL to commit
        $this->primaryQueue->add($this->getJobIdFromSecondaryJobId($jobId), $crawlUri);

        if ($crawlUri->wasMarkedProcessed()) {
            ++$this->toSkip;
        }
    }

    public function getNext(string $jobId, int $skip = 0): ?CrawlUri
    {
        // If we have it in the primary queue, early return
        $next = $this->primaryQueue->getNext($this->getJobIdFromSecondaryJobId($jobId), $skip);
        if (null !== $next) {
            return $next;
        }

        // Otherwise we check in the secondary and add it to our primary queue for consecutive calls.
        // At this point we have to skip the number of queue entries that were marked in the primary
        // queue.
        $next = $this->secondaryQueue->getNext($jobId, $this->toSkip + $skip);

        if (null !== $next) {
            $this->primaryQueue->add($this->getJobIdFromSecondaryJobId($jobId), $next);

            return $next;
        }

        return null;
    }

    public function countAll(string $jobId): int
    {
        // We have to commit and then return from the secondary
        $this->commit($jobId);

        return $this->secondaryQueue->countAll($jobId);
    }

    public function countPending(string $jobId): int
    {
        // We have to commit and then return from the secondary
        $this->commit($jobId);

        return $this->secondaryQueue->countPending($jobId);
    }

    public function getAll(string $jobId): \Generator
    {
        // We have to commit and then return from the secondary
        $this->commit($jobId);

        return $this->secondaryQueue->getAll($jobId);
    }

    /**
     * Mirrors the data from the primary queue to the secondary queue.
     */
    public function commit(string $jobId): void
    {
        foreach ($this->primaryQueue->getAll($this->getJobIdFromSecondaryJobId($jobId)) as $crawlUri) {
            $this->secondaryQueue->add($jobId, $crawlUri);
        }
    }

    private function getJobIdFromSecondaryJobId(string $jobId): string
    {
        if (isset($this->jobIdMapper[$jobId])) {
            return $this->jobIdMapper[$jobId];
        }

        $baseUris = $this->secondaryQueue->getBaseUris($jobId);
        $primaryJobId = $this->primaryQueue->createJobId($baseUris);

        foreach ($baseUris as $baseUri) {
            $primaryBaseCrawlUri = $this->primaryQueue->get($primaryJobId, $baseUri);

            // Mark the base URI processed if it already is
            $baseCrawlUri = $this->secondaryQueue->get($jobId, $baseUri);

            if ($baseCrawlUri->isProcessed()) {
                $primaryBaseCrawlUri->markProcessed();
                $this->primaryQueue->add($primaryJobId, $primaryBaseCrawlUri);
            }
        }

        return $this->jobIdMapper[$jobId] = $primaryJobId;
    }
}
