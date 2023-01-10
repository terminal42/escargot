<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Subscriber;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;

interface SubscriberInterface
{
    public const DECISION_POSITIVE = 'positive';
    public const DECISION_ABSTAIN = 'abstain';
    public const DECISION_NEGATIVE = 'negative';

    /**
     * Called before a request is executed.
     * You may operate on the CrawlUri instance and add tags to it.
     *
     * Returning a positive decision will cause the request to be
     * executed no matter what other subscribers return.
     * It will also cause needsContent() to be called on this subscriber.
     *
     * Returning an abstain decision will not cause the request to be
     * executed. However, if any other subscriber returns a positive
     * decision, needsContent() will still be called on this subscriber.
     *
     * Returning a negative decision will make sure, needsContent() is
     * not called on this subscriber, no matter whether another subscriber
     * returns a positive decision thus causing the request to be executed.
     *
     * @param CrawlUri $crawlUri The current CrawlUri instance
     *
     * @return string One of the DECISION_* constants
     */
    public function shouldRequest(CrawlUri $crawlUri): string;

    /**
     * Called on the first chunk that arrives.
     * You may operate on the CrawlUri instance and add tags to it.
     *
     * Returning a positive decision will cause the request to be finished
     * (whole response content is loaded) no matter what other subscribers return.
     * It will also cause onLastChunk() to be called on this subscriber.
     *
     * Returning an abstain decision will not cause the request to be
     * finished. However, if any other subscriber returns a positive
     * decision, onLastChunk() will still be called on this subscriber.
     *
     * Returning a negative decision will make sure, onLastChunk() is
     * not called on this subscriber, no matter whether another subscriber
     * returns a positive decision thus causing the request to be completed.
     *
     * @param CrawlUri          $crawlUri The current CrawlUri instance
     * @param ResponseInterface $response The current response
     * @param ChunkInterface    $chunk    The first chunk
     *
     * @return string One of the DECISION_* constants
     */
    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string;

    /**
     * Called on the last chunk that arrives.
     *
     * @param CrawlUri          $crawlUri The current CrawlUri instance
     * @param ResponseInterface $response The current response
     * @param ChunkInterface    $chunk    The last chunk
     */
    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void;
}
