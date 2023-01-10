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

interface QueueInterface
{
    /**
     * Creates a new job ID. This ID has to be unique
     * and not exceed 128 characters.
     * The queue implementation MUST also add all
     * provided $baseUris to the queue and mark them
     * as NOT processed.
     */
    public function createJobId(BaseUriCollection $baseUris): string;

    /**
     * Validate if a job ID is still valid.
     * If e.g. you implement a Redis storage and the ID provided
     * does not exist anymore, it's invalid.
     */
    public function isJobIdValid(string $jobId): bool;

    /**
     * Deletes a given job.
     */
    public function deleteJobId(string $jobId): void;

    /**
     * To pick up a job later on, the queue has to be able
     * to return the base URI collectoin the job was created with.
     */
    public function getBaseUris(string $jobId): BaseUriCollection;

    /**
     * Returns a CrawlUri for a given UriInterface if already
     * added to the queue.
     */
    public function get(string $jobId, UriInterface $baseUri): ?CrawlUri;

    /**
     * Adds a new CrawlUri instance to the queue.
     * This method has to be implemented in an idempotent way so multiple
     * consecutive add() calls with the same parameters should not
     * cause the same CrawlUri to be added to the queue multiple times
     * as one URI can only ever be crawled once.
     */
    public function add(string $jobId, CrawlUri $crawlUri): void;

    /**
     * Gets the next CrawlUri off the queue. Note that there's no
     * blocking feature implemented. So if you have multiple
     * workers working on the same queue and the same job ID,
     * you have to ensure the data is not processed multiple
     * times yourself.
     * This method has to be implemented in an idempotent way so multiple
     * consecutive getNext() calls with the same parameters should not
     * cause the the state of the queue to change. This method is also
     * used to check if there's still anything to process on the queue
     * even if the returned CrawlUri is not being used.
     *
     * The skip argument is used to skip n queued entries. It is especially
     * useful for the LazyQueue implementation so it can skip n entries
     * it's already processed and stored in the primary queue.
     */
    public function getNext(string $jobId, int $skip = 0): ?CrawlUri;

    /**
     * Returns the total of all URIs.
     */
    public function countAll(string $jobId): int;

    /**
     * Returns the total of still pending URIs.
     */
    public function countPending(string $jobId): int;

    /**
     * Returns all CrawlUri instances in the queue.
     *
     * @return \Generator & iterable<int, CrawlUri>
     */
    public function getAll(string $jobId): \Generator;
}
