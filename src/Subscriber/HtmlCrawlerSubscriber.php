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

use Nyholm\Psr7\Uri;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;

final class HtmlCrawlerSubscriber implements SubscriberInterface, EscargotAwareInterface
{
    use EscargotAwareTrait;

    public const TAG_REL_NOFOLLOW = 'rel-nofollow';
    public const TAG_NO_TEXT_HTML_TYPE = 'no-txt-html-type';

    public function shouldRequest(CrawlUri $crawlUri, string $currentDecision): string
    {
        $uri = $crawlUri->getUri();

        // Only crawl URIs of the same host
        if (!$this->escargot->getBaseUris()->containsHost($uri->getHost())) {
            $this->escargot->log(
                LogLevel::DEBUG,
                $crawlUri->createLogMessage('Do not request because the host is not allowed by the base URI collection.')
            );

            return self::DECISION_NEGATIVE;
        }

        // Skip rel="nofollow" links
        if ($crawlUri->hasTag(self::TAG_REL_NOFOLLOW)) {
            $this->escargot->log(
                LogLevel::DEBUG,
                $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the "rel" attribute contained "nofollow".')
            );

            return self::DECISION_NEGATIVE;
        }

        // Skip the links that have the "type" attribute set and it's not text/html
        if ($crawlUri->hasTag(self::TAG_NO_TEXT_HTML_TYPE)) {
            $this->escargot->log(
                LogLevel::DEBUG,
                $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the "type" attribute was present and did not contain "text/html".')
            );

            return self::DECISION_NEGATIVE;
        }

        // If the current decision is negative, we do not change this.
        // Otherwise, we want to continue to crawl
        return self::DECISION_NEGATIVE === $currentDecision ? self::DECISION_NEGATIVE : self::DECISION_POSITIVE;
    }

    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk, string $currentDecision): string
    {
        if ($this->isHtml($response)) {
            return self::DECISION_POSITIVE;
        }

        return $currentDecision;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        // This could happen if another subscriber requested the content
        if (!$this->isHtml($response)) {
            return;
        }

        $crawler = new Crawler($response->getContent());
        $linkCrawler = $crawler->filter('a');

        foreach ($linkCrawler as $node) {
            $link = new Link($node, (string) $crawlUri->getUri()->withPath('')->withQuery('')->withFragment(''));
            $uri = new Uri($link->getUri());

            // Normalize uri
            $uri = CrawlUri::normalizeUri($uri);

            // Add to queue
            $newCrawlUri = $this->escargot->addUriToQueue($uri, $crawlUri);

            // Add a tag to the new CrawlUri instance if it was marked with rel="nofollow"
            if ($node->hasAttribute('rel') && 'nofollow' === $node->getAttribute('rel')) {
                $newCrawlUri->addTag(self::TAG_REL_NOFOLLOW);
            }

            // Add a tag to the new CrawlUri instance if it was marked with a type attribute and it did not contain "text/html"
            if ($node->hasAttribute('type') && 'text/html' !== $node->getAttribute('type')) {
                $newCrawlUri->addTag(self::TAG_NO_TEXT_HTML_TYPE);
            }
        }
    }

    private function isHtml(ResponseInterface $response): bool
    {
        if (!\in_array('content-type', array_keys($response->getHeaders()), true)) {
            return false;
        }

        return false !== strpos($response->getHeaders()['content-type'][0], 'text/html');
    }
}
