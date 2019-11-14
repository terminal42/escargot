<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
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
    public const DECISION_NEGATIVE = 'negative';
    public const DECISION_ABSTAIN = 'abstain';

    /**
     * Called before a request is executed.
     * You may operate on the CrawlUri instance and add attributes to it.
     *
     * Only if the resulting decision of all subscribers is positive,
     * the request is going to be executed.
     *
     * @param CrawlUri $crawlUri        The current CrawlUri instance
     * @param string   $currentDecision One of the DECISION_* constants
     *
     * @return string One of the DECISION_* constants
     */
    public function shouldRequest(CrawlUri $crawlUri, string $currentDecision): string;

    /**
     * Called on the first chunk that arrives.
     * You may operate on the CrawlUri instance and add attributes to it.

     * Only if the resulting decision of all subscribers is positive,
     * the response is not going to be early aborted.
     *
     * @param CrawlUri          $crawlUri        The current CrawlUri instance
     * @param ResponseInterface $response        The current response
     * @param ChunkInterface    $chunk           The first chunk
     * @param string            $currentDecision One of the DECISION_* constants
     *
     * @return string One of the DECISION_* constants
     */
    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk, string $currentDecision): string;

    /**
     * Called on the last chunk that arrives.
     *
     * @param CrawlUri          $crawlUri The current CrawlUri instance
     * @param ResponseInterface $response The current response
     * @param ChunkInterface    $chunk    The last chunk
     */
    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void;
}
