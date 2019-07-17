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

interface QueueInterface
{
    /**
     * Creates a new job ID. This ID has to be unique.
     * The queue implementation MUST also add the
     * provided $baseUri to the queue and NOT mark
     * as processed.
     */
    public function createJobId(UriInterface $baseUri): string;

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
     * to return the base URI the job was created with.
     */
    public function getBaseUri(string $jobId): UriInterface;

    /**
     * Returns true if a CrawlUri has already been added.
     * This does not mean it has been processed (see isProcessed()).
     */
    public function has(string $jobId, CrawlUri $crawlUri): bool;

    /**
     * Adds a new CrawlUri instance to the queue.
     * This method has to be implemented in an idempotent way so multiple
     * consecutive add() calls with the same parameters should not
     * cause the same CrawlUri to be added to the queue multiple times
     * as one URI can only ever be crawled once.
     */
    public function add(string $jobId, CrawlUri $crawlUri): void;

    /**
     * Marks a CrawlUri as processed.
     */
    public function markProcessed(string $jobId, CrawlUri $crawlUri): void;

    /**
     * Returns true if a CrawlUri has been processed.
     */
    public function isProcessed(string $jobId, CrawlUri $crawlUri): bool;

    /**
     * Allows you to check if there are any pending URIs to crawl.
     */
    public function hasPending(string $jobId): bool;

    /**
     * Gets the next CrawlUri off the queue. Note that there's no
     * blocking feature implemented. So if you have multiple
     * workers working on the same queue and the same job ID,
     * you have to ensure the data is not processed multiple
     * times yourself.
     */
    public function getNext(string $jobId): ?CrawlUri;

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
