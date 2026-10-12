<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Fixture;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\SubscriberInterface;
use Terminal42\Escargot\SubscriberLoggerTrait;

final class SearchIndexSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
    use EscargotAwareTrait;
    use LoggerAwareTrait;
    use SubscriberLoggerTrait;

    /**
     * @var list<CrawlUri>
     */
    private array $uris = [];

    /**
     * @return list<CrawlUri>
     */
    public function getUris(): array
    {
        return $this->uris;
    }

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        $reason = $this->getBlockedReason($crawlUri);

        if (null !== $reason) {
            $this->logWithCrawlUri($crawlUri, LogLevel::DEBUG, $reason);

            return self::DECISION_NEGATIVE;
        }

        if ($this->escargot->getBaseUris()->containsHost($crawlUri->getUri()->getHost())) {
            return self::DECISION_POSITIVE;
        }

        return self::DECISION_ABSTAIN;
    }

    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
    {
        return self::DECISION_POSITIVE;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        $this->uris[] = $crawlUri;
    }

    private function getBlockedReason(CrawlUri $crawlUri): string|null
    {
        // Check the original crawlUri to see if that one contained nofollow information
        if (null !== $crawlUri->getFoundOn() && ($originalCrawlUri = $this->escargot->getCrawlUri($crawlUri->getFoundOn()))) {
            if ($originalCrawlUri->hasTag(RobotsSubscriber::TAG_NOFOLLOW)) {
                return 'Do not request because when the crawl URI was found, the robots information disallowed following this URI.';
            }
        }

        // Skip the links that are disallowed by robots.txt
        if ($crawlUri->hasTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT)) {
            return 'Do not request because it was disallowed by the robots.txt.';
        }

        // Skip rel="nofollow" links
        if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_REL_NOFOLLOW)) {
            return 'Do not request because when the crawl URI was found, the "rel" attribute contained "nofollow".';
        }

        // Skip the links that have the "type" attribute set and it's not text/html
        if ($crawlUri->hasTag(HtmlCrawlerSubscriber::TAG_NO_TEXT_HTML_TYPE)) {
            return 'Do not request because when the crawl URI was found, the "type" attribute was present and did not contain "text/html".';
        }

        return null;
    }
}
