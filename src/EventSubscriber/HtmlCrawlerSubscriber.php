<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\EventSubscriber;

use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Event\ResponseEvent;

final class HtmlCrawlerSubscriber implements EventSubscriberInterface
{
    public function onResponse(ResponseEvent $event): void
    {
        // We cannot crawl any HTML until we have the complete response
        if (!$event->getCurrentChunk()->isLast()) {
            return;
        }

        // Skip empty responses
        if ($this->hasNoContent($event)) {
            return;
        }

        // Skip responses that contain an X-Robots-Tag header with nofollow
        if ($this->xRobotsTagHeaderContainsNofollow($event)) {
            return;
        }

        $crawler = new Crawler($event->getResponse()->getContent());

        // Skip responses that contain nofollow in the robots meta tag
        if ($this->robotsMetaTagContainsNofollow($event, $crawler)) {
            return;
        }

        // Now crawl for links
        $linkCrawler = $crawler->filter('a');

        foreach ($linkCrawler as $node) {
            $link = new Link($node, (string) $event->getCrawlUri()->getUri()->withPath('')->withQuery('')->withFragment(''));
            $uri = new Uri($link->getUri());

            // Normalize uri
            $uri = CrawlUri::normalizeUri($uri);

            if (!$this->shouldCrawl($event, $uri, $node)) {
                continue;
            }

            // Mark URI to process
            $event->getEscargot()->addUriToQueue($uri, $event->getCrawlUri());
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ResponseEvent::class => 'onResponse',
        ];
    }

    private function hasNoContent(ResponseEvent $event): bool
    {
        if (204 === $event->getResponse()->getStatusCode() || '' === $event->getResponse()->getContent()) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage('Skipped this URI because it did not contain any content (or was indicated so by the 204 status code).')
            );

            return true;
        }

        return false;
    }

    private function xRobotsTagHeaderContainsNofollow(ResponseEvent $event): bool
    {
        if (isset($event->getResponse()->getHeaders()['x-robots-tag'][0])
            && false !== strpos($event->getResponse()->getHeaders()['x-robots-tag'][0], 'nofollow')
        ) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage('Skipped all links on this URI because the X-Robots-Tag contained "nofollow".')
            );

            return true;
        }

        return false;
    }

    private function robotsMetaTagContainsNofollow(ResponseEvent $event, Crawler $crawler): bool
    {
        $metaCrawler = $crawler->filter('head meta[name="robots"]');
        $robotsMeta = $metaCrawler->count() ? $metaCrawler->first()->attr('content') : '';

        if (false !== strpos($robotsMeta, 'nofollow')) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage('Skipped all links on this URI because the <meta name="robots"> tag contained "nofollow".')
            );

            return true;
        }

        return false;
    }

    private function shouldCrawl(ResponseEvent $event, UriInterface $uri, \DOMElement $node): bool
    {
        // Skip non http URIs
        if (!\in_array($uri->getScheme(), ['http', 'https'], true)) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage(sprintf('Skipped URI "%s" because it does not start with http(s).', (string) $uri))
            );

            return false;
        }

        // Skip rel="nofollow" links
        if ('nofollow' === $node->getAttribute('rel')) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage(sprintf('Skipped URI "%s" because the "rel" attribute contains "nofollow".', (string) $uri))
            );

            return false;
        }

        // Skip the links that have the "type" attribute set and it's not text/html
        if ($node->hasAttribute('type') && 'text/html' !== $node->getAttribute('type')) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage(sprintf('Skipped URI "%s" because the "type" attribute does not contain "text/html".', (string) $uri))
            );

            return false;
        }

        // Only crawl URIs of the same host
        if (!$event->getEscargot()->getBaseUris()->containsHost($uri->getHost())) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage(sprintf('Skipped URI "%s" because the host is not allowed by the base URI collection.', (string) $uri))
            );

            return false;
        }

        return true;
    }
}
