<?php

declare(strict_types=1);

namespace Terminal42\Escargot;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class SubscriberLogger extends AbstractLogger
{
    private readonly string|null $subscriberClass;

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

    /**
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $context = array_merge($context, ['source' => $this->subscriberClass]);

        $this->decorated->log($level, $message, $context);
    }
}
