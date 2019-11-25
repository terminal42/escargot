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

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        // We don't want to force the request but if another subscriber does, we want to know the headers
        return self::DECISION_ABSTAIN;
    }

    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
    {
        // If it's an HTML response, we want the whole content to extract additional URIs
        if (Util::isOfContentType($response, 'text/html')) {
            return self::DECISION_POSITIVE;
        }

        // Otherwise, we don't need the content
        return self::DECISION_NEGATIVE;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
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
}
