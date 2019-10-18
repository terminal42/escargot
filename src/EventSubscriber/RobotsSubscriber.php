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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Event\PreRequestEvent;
use Terminal42\Escargot\Event\ResponseEvent;
use webignition\RobotsTxt\File\File;
use webignition\RobotsTxt\File\Parser;
use webignition\RobotsTxt\Inspector\Inspector;

class RobotsSubscriber implements EventSubscriberInterface
{
    /**
     * @var array
     */
    private $robotsTxtCache = [];

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        // Early abort if X-Robots-Tag contains noindex
        if ($event->getCurrentChunk()->isFirst() && \in_array('x-robots-tag', array_keys($response->getHeaders()), true)) {
            $tag = $response->getHeaders()['x-robots-tag'][0];

            if (false !== strpos($tag, 'noindex')) {
                $response->cancel();

                $event->getEscargot()->log(
                    LogLevel::DEBUG,
                    $event->getCrawlUri()->createLogMessage(
                        'Early abort response as the X-Robots-Tag header contains "noindex".'
                    )
                );
            }
        }
    }

    public function onPreRequest(PreRequestEvent $event): void
    {
        $robotsTxt = $this->getRobotsTxtFile($event);

        if (null === $robotsTxt) {
            return;
        }

        // If this is a base URI we check the robots.txt for sitemap entries and add those to the queue
        if (0 === $event->getCrawlUri()->getLevel()) {
            $this->handleSitemap($event, $robotsTxt);
        }

        // Check if an URI is allowed by the robots.txt and if not, abort the request
        $inspector = new Inspector($robotsTxt, $event->getEscargot()->getUserAgent());

        if (!$inspector->isAllowed($event->getCrawlUri()->getUri()->getPath())) {
            $event->getEscargot()->log(
                LogLevel::DEBUG,
                $event->getCrawlUri()->createLogMessage('Will not crawl URI was disallowed by robots.txt!')
            );

            $event->abortRequest();
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ResponseEvent::class => 'onResponse',
            PreRequestEvent::class => 'onPreRequest',
        ];
    }

    private function getRobotsTxtFile(PreRequestEvent $event): ?File
    {
        $robotsTxtUri = $this->getRobotsTxtUri($event->getCrawlUri());

        // Check the cache
        if (isset($this->robotsTxtCache[(string) $robotsTxtUri])) {
            return $this->robotsTxtCache[(string) $robotsTxtUri];
        }

        try {
            $response = $event->getEscargot()->getClient()->request('GET', (string) $robotsTxtUri);
        } catch (TransportExceptionInterface $e) {
            return $this->robotsTxtCache[(string) $robotsTxtUri] = null;
        }

        if (null === $response || 200 !== $response->getStatusCode()) {
            return $this->robotsTxtCache[(string) $robotsTxtUri] = null;
        }

        $robotsTxtContent = $response->getContent();

        $parser = new Parser();
        $parser->setSource($robotsTxtContent);

        return $this->robotsTxtCache[(string) $robotsTxtUri] = $parser->getFile();
    }

    private function getRobotsTxtUri(CrawlUri $crawlUri): UriInterface
    {
        // Make sure we get the correct URI to the robots.txt
        return $crawlUri->getUri()->withPath('/robots.txt')->withFragment('')->withQuery('');
    }

    private function handleSitemap(PreRequestEvent $event, File $robotsTxt): void
    {
        // Level 1 because 0 is the base URI and 1 is the robots.txt
        $foundOn = new CrawlUri($this->getRobotsTxtUri($event->getCrawlUri()), 1, true);

        foreach ($robotsTxt->getNonGroupDirectives()->getByField('sitemap')->getDirectives() as $directive) {
            try {
                $response = $event->getEscargot()->getClient()->request('GET', $directive->getValue()->get());
            } catch (TransportExceptionInterface $e) {
                continue;
            }

            if (null === $response || 200 !== $response->getStatusCode()) {
                continue;
            }

            $urls = new \SimpleXMLElement($response->getContent());

            foreach ($urls as $url) {
                // Add it to the queue if not present already
                $event->getEscargot()->addUriToQueue(new Uri((string) $url->loc), $foundOn);
            }
        }
    }
}
