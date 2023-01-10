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

interface FinishedCrawlingSubscriberInterface
{
    /**
     * Called when crawling was finished.
     * Note: This does not mean when crawling is finished completely but even if maxRequests is reached.
     * To see whether you're done completely you may compare the number of pending URIs and the total
     * URIs on the queue.
     */
    public function finishedCrawling(): void;
}
