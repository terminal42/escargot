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

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class SubscriberLogger extends AbstractLogger
{
    private string|null $subscriberClass = null;

    public function __construct(
        private readonly LoggerInterface $decorated,
        string $subscriberClass,
    ) {
        // Anonymous class names contain null bytes so let's standardize them a little
        if (str_contains($subscriberClass, '@anonymous')) {
            $subscriberClass = 'class@anonymous:'.basename($subscriberClass);
            $subscriberClass = preg_replace('/\.php(.+)$/', '', $subscriberClass);
        }
        $this->subscriberClass = $subscriberClass;
    }

    public function logWithCrawlUri(CrawlUri $crawlUri, string $level, string $message): void
    {
        $this->log($level, $message, ['crawlUri' => $crawlUri]);
    }

    public function log($level, $message, array $context = []): void
    {
        $context = array_merge($context, ['source' => $this->subscriberClass]);

        $this->decorated->log($level, $message, $context);
    }
}
