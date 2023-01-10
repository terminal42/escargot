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

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\HttpUriFactory;
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

        // If we hit max depth level, we don't even need to analyze the content because nothing is to be added
        // to the queue anymore anyway
        if ($this->escargot->isMaxDepthReached($crawlUri)) {
            return self::DECISION_NEGATIVE;
        }

        // We don't want to force the request but if another subscriber does, we want to know the contents
        return self::DECISION_ABSTAIN;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        $crawler = new Crawler($response->getContent(), (string) $crawlUri->getUri());

        // Links
        $linkCrawler = $crawler->filterXPath('descendant-or-self::a');
        foreach ($linkCrawler->links() as $link) {
            $this->addNewUriToQueueFromNode($crawlUri, $link->getUri(), $link->getNode());
        }

        // Canonical
        $canonicalCrawler = $crawler->filterXPath('descendant-or-self::head/descendant-or-self::*/link[@rel = \'canonical\'][@href]');
        if ($canonicalCrawler->count()) {
            $this->addNewUriToQueueFromNode($crawlUri, $canonicalCrawler->first()->attr('href'), $canonicalCrawler->first()->getNode(0));
        }
    }

    private function addNewUriToQueueFromNode(CrawlUri $crawlUri, string $uri, \DOMElement $node): void
    {
        // We only support http(s):// URIs
        if (!preg_match('@^https?://.*$@', $uri)) {
            return;
        }

        try {
            $uri = HttpUriFactory::create($uri);
        } catch (\InvalidArgumentException $e) {
            $this->logWithCrawlUri(
                $crawlUri,
                LogLevel::DEBUG,
                sprintf(
                    'Could not add "%s" to the queue because the link is invalid.',
                    $uri
                )
            );

            return;
        }

        $uri = CrawlUri::normalizeUri($uri);

        // Skip completely
        if ($node->hasAttribute('data-escargot-ignore')) {
            $this->logWithCrawlUri(
                $crawlUri,
                LogLevel::DEBUG,
                sprintf(
                    'Did not add "%s" to the queue because it was marked as "data-escargot-ignore".',
                    $uri
                )
            );

            return;
        }

        // Add to queue
        $newCrawlUri = $this->escargot->addUriToQueue($uri, $crawlUri);

        // Add all data attributes as tags for e.g. other subscribers
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attribute) {
                if (0 === strpos($attribute->name, 'data-')) {
                    $newCrawlUri->addTag(substr($attribute->name, 5));
                }
            }
        }

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
