<?php

declare(strict_types=1);

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
