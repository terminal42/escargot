<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Queue;

use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;

final class LazyQueue implements QueueInterface
{
    /**
     * @var array<string, string>
     */
    private array $jobIdMapper = [];

    /**
     * Number of pending secondary entries already cached in the primary queue per job.
     *
     * @var array<string, int>
     */
    private array $secondaryOffsets = [];

    public function __construct(
        private readonly QueueInterface $primaryQueue,
        private readonly QueueInterface $secondaryQueue,
    ) {
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

        if (isset($this->jobIdMapper[$jobId])) {
            $this->primaryQueue->deleteJobId($this->jobIdMapper[$jobId]);
        }

        unset($this->jobIdMapper[$jobId], $this->secondaryOffsets[$jobId]);
    }

    public function getBaseUris(string $jobId): BaseUriCollection
    {
        // Must be present in our primary queue as well
        return $this->primaryQueue->getBaseUris($this->getJobIdFromSecondaryJobId($jobId));
    }

    public function get(string $jobId, UriInterface $uri): CrawlUri|null
    {
        // If we have it in the primary queue, early return
        $crawlUri = $this->primaryQueue->get($this->getJobIdFromSecondaryJobId($jobId), $uri);

        if (null !== $crawlUri) {
            return $crawlUri;
        }

        // Otherwise we check in the secondary and add it to our primary queue for consecutive calls
        $crawlUri = $this->secondaryQueue->get($jobId, $uri);

        if (null !== $crawlUri) {
            $crawlUri = clone $crawlUri;
            $this->primaryQueue->add($this->getJobIdFromSecondaryJobId($jobId), $crawlUri);

            return $crawlUri;
        }

        return null;
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        // We only add in primary and mark the URL to commit
        $this->primaryQueue->add($this->getJobIdFromSecondaryJobId($jobId), $crawlUri);
    }

    public function getNext(string $jobId, int $skip = 0): CrawlUri|null
    {
        // If we have it in the primary queue, early return
        $next = $this->primaryQueue->getNext($this->getJobIdFromSecondaryJobId($jobId), $skip);
        if (null !== $next) {
            return $next;
        }

        // Otherwise we check in the secondary and add it to our primary queue for consecutive calls.
        // At this point we have to skip entries already cached in the primary queue and account
        // for pending entries that were skipped there.
        if ($skip > 0) {
            $skip -= $this->primaryQueue->countPending($this->getJobIdFromSecondaryJobId($jobId));
        }

        return $this->getNextFromSecondaryQueue($jobId, $skip);
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
            $this->secondaryQueue->add($jobId, clone $crawlUri);
        }

        $this->secondaryOffsets[$jobId] = 0;
    }

    private function getNextFromSecondaryQueue(string $jobId, int $skip): CrawlUri|null
    {
        $primaryJobId = $this->getJobIdFromSecondaryJobId($jobId);
        $offset = $this->secondaryOffsets[$jobId] ?? 0;

        while (null !== ($next = $this->secondaryQueue->getNext($jobId, $offset))) {
            $this->secondaryOffsets[$jobId] = ++$offset;

            if (null !== $this->primaryQueue->get($primaryJobId, $next->getUri())) {
                continue;
            }

            $next = clone $next;
            $this->primaryQueue->add($primaryJobId, $next);

            if ($skip > 0) {
                --$skip;
                continue;
            }

            return $next;
        }

        return null;
    }

    private function getJobIdFromSecondaryJobId(string $jobId): string
    {
        if (isset($this->jobIdMapper[$jobId])) {
            return $this->jobIdMapper[$jobId];
        }

        $baseUris = $this->secondaryQueue->getBaseUris($jobId);
        $primaryJobId = $this->primaryQueue->createJobId($baseUris);

        foreach ($baseUris as $baseUri) {
            // Preserve metadata and processing state of the base URI
            $baseCrawlUri = $this->secondaryQueue->get($jobId, $baseUri);

            if (null !== $baseCrawlUri) {
                $this->primaryQueue->add($primaryJobId, clone $baseCrawlUri);
            }
        }

        return $this->jobIdMapper[$jobId] = $primaryJobId;
    }
}
