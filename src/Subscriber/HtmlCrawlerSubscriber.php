<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2020, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Subscriber;

use Nyholm\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\SubscriberLoggerTrait;

final class HtmlCrawlerSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
    use EscargotAwareTrait;
    use LoggerAwareTrait;
    use SubscriberLoggerTrait;

    public const TAG_REL_NOFOLLOW = 'rel-nofollow';
    public const TAG_NO_TEXT_HTML_TYPE = 'no-txt-html-type';

    public function shouldRequest(CrawlUri $crawlUri): string
    {
        // We don't want to force the request but if another subscriber does, we want to know the headers
        return self::DECISION_ABSTAIN;
    }

    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
    {
        // If it's not an HTML response, we cannot extract anything anyway
        if (!Util::isOfContentType($response, 'text/html')) {
            return self::DECISION_NEGATIVE;
        }

        // We don't want to force the request but if another subscriber does, we want to know the contents
        return self::DECISION_ABSTAIN;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        $crawler = new Crawler($response->getContent());
        $linkCrawler = $crawler->filter('a');

        foreach ($linkCrawler as $node) {
            $link = new Link($node, (string) $crawlUri->getUri()->withPath('')->withQuery('')->withFragment(''));

            // We only support http(s):// links
            if (!preg_match('@^https?://.*$@', $link->getUri())) {
                continue;
            }

            try {
                $uri = new Uri($link->getUri());
            } catch (\InvalidArgumentException $e) {
                $this->logWithCrawlUri(
                    $crawlUri,
                    LogLevel::DEBUG,
                    sprintf(
                        'Could not add "%s" to the queue because the link is invalid.',
                        $link->getUri()
                    )
                );
                continue;
            }

            // Normalize uri
            $uri = CrawlUri::normalizeUri($uri);

            // Add to queue
            $newCrawlUri = $this->escargot->addUriToQueue($uri, $crawlUri);

            // Add a tag to the new CrawlUri instance if it was marked with rel="nofollow"
            if ($node->hasAttribute('rel') && false !== strpos($node->getAttribute('rel'), 'nofollow')) {
                $newCrawlUri->addTag(self::TAG_REL_NOFOLLOW);
            }

            // Add a tag to the new CrawlUri instance if it was marked with a type attribute and it did not contain "text/html"
            if ($node->hasAttribute('type') && 'text/html' !== $node->getAttribute('type')) {
                $newCrawlUri->addTag(self::TAG_NO_TEXT_HTML_TYPE);
            }
        }
    }
}
