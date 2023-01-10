<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

trait SubscriberLoggerTrait
{
    public function logWithCrawlUri(CrawlUri $crawlUri, string $level, string $message): void
    {
        if (!$this->logger instanceof SubscriberLogger) {
            return;
        }

        $this->logger->logWithCrawlUri($crawlUri, $level, $message);
    }
}
