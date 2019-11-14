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
use Psr\Http\Message\UriInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use webignition\RobotsTxt\File\File;
use webignition\RobotsTxt\File\Parser;
use webignition\RobotsTxt\Inspector\Inspector;

final class RobotsSubscriber implements SubscriberInterface, EscargotAwareInterface
{
    use EscargotAwareTrait;

    public const TAG_NOINDEX = 'noindex';
    public const TAG_NOFOLLOW = 'nofollow';
    public const TAG_DISALLOWED_ROBOTS_TXT = 'disallowed-robots-txt';

    /**
     * @var array<string,File>
     */
    private $robotsTxtCache = [];

    /**
     * {@inheritdoc}
     */
    public function shouldRequest(CrawlUri $crawlUri, string $currentDecision): string
    {
        // Add robots.txt information
        $this->handleDisallowedByRobotsTxtTag($crawlUri);

        // Check the original crawlUri to see if that one contained nofollow information
        if (null !== $crawlUri->getFoundOn() && ($originalCrawlUri = $this->escargot->getCrawlUri($crawlUri->getFoundOn()))) {
            if ($originalCrawlUri->hasTag(self::TAG_NOFOLLOW)) {
                $this->escargot->log(
                    \get_class($this),
                    LogLevel::DEBUG,
                    $crawlUri->createLogMessage('Do not request because when the crawl URI was found, the robots information disallowed following this URI.')
                );

                return self::DECISION_NEGATIVE;
            }
        }

        // We don't care if the URI is going to be crawled or not, that's up to other subscribers.
        return $currentDecision;
    }

    /**
     * {@inheritdoc}
     */
    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk, string $currentDecision): string
    {
        // Add tags
        if (\in_array('x-robots-tag', array_keys($response->getHeaders()), true)) {
            $xRobotsTagValue = $response->getHeaders()['x-robots-tag'][0];
            $this->handleNoindexNofollowTags(
                $crawlUri,
                $xRobotsTagValue,
                'Added the "%tag%" tag because the X-Robots-Tag header contained "%value%".'
            );
        }

        // We don't care if the rest of the response is loaded, that's up to other subscribers.
        return $currentDecision;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        if (!\in_array('content-type', array_keys($response->getHeaders()), true)) {
            return;
        }

        if (false === strpos($response->getHeaders()['content-type'][0], 'text/html')) {
            return;
        }

        $crawler = new Crawler($response->getContent());
        $metaCrawler = $crawler->filter('head meta[name="robots"]');
        $robotsMetaTagValue = $metaCrawler->count() ? $metaCrawler->first()->attr('content') : '';

        $this->handleNoindexNofollowTags(
            $crawlUri,
            $robotsMetaTagValue,
            'Added the "%tag%" tag because the <meta name="robots"> tag contained "%value%".'
        );
    }

    private function handleNoindexNofollowTags(CrawlUri $crawlUri, string $value, string $messageTpl): void
    {
        $tags = [];

        if (false !== strpos($value, 'noindex')) {
            $tags[] = self::TAG_NOINDEX;
        }

        if (false !== strpos($value, 'nofollow')) {
            $tags[] = self::TAG_NOFOLLOW;
        }

        foreach ($tags as $tag) {
            $crawlUri->addTag($tag);

            $this->escargot->log(
                \get_class($this),
                LogLevel::DEBUG,
                $crawlUri->createLogMessage(str_replace(['%value%', '%tag%'], [$value, $tag], $messageTpl))
            );
        }
    }

    private function handleDisallowedByRobotsTxtTag(CrawlUri $crawlUri): void
    {
        $robotsTxt = $this->getRobotsTxtFile($crawlUri);

        if (null === $robotsTxt) {
            return;
        }

        // If this is a base URI we check the robots.txt for sitemap entries and add those to the queue
        if (0 === $crawlUri->getLevel()) {
            $this->handleSitemap($crawlUri, $robotsTxt);
        }

        // Check if an URI is allowed by the robots.txt
        $inspector = new Inspector($robotsTxt, $this->escargot->getUserAgent());

        if (!$inspector->isAllowed($crawlUri->getUri()->getPath())) {
            $crawlUri->addTag(self::TAG_DISALLOWED_ROBOTS_TXT);

            $this->escargot->log(
                \get_class($this),
                LogLevel::DEBUG,
                $crawlUri->createLogMessage(sprintf(
                    'Added the "%s" tag because of the robots.txt content.',
                    self::TAG_DISALLOWED_ROBOTS_TXT
                ))
            );
        }
    }

    private function getRobotsTxtFile(CrawlUri $crawlUri): ?File
    {
        $robotsTxtUri = $this->getRobotsTxtUri($crawlUri);

        // Check the cache
        if (isset($this->robotsTxtCache[(string) $robotsTxtUri])) {
            return $this->robotsTxtCache[(string) $robotsTxtUri];
        }

        try {
            $response = $this->escargot->getClient()->request('GET', (string) $robotsTxtUri);

            if (200 !== $response->getStatusCode()) {
                return $this->robotsTxtCache[(string) $robotsTxtUri] = null;
            }

            $robotsTxtContent = $response->getContent();

            $parser = new Parser();
            $parser->setSource($robotsTxtContent);

            return $this->robotsTxtCache[(string) $robotsTxtUri] = $parser->getFile();
        } catch (TransportExceptionInterface $exception) {
            return $this->robotsTxtCache[(string) $robotsTxtUri] = null;
        }
    }

    private function getRobotsTxtUri(CrawlUri $crawlUri): UriInterface
    {
        // Make sure we get the correct URI to the robots.txt
        return $crawlUri->getUri()->withPath('/robots.txt')->withFragment('')->withQuery('');
    }

    private function handleSitemap(CrawlUri $crawlUri, File $robotsTxt): void
    {
        // Level 1 because 0 is the base URI and 1 is the robots.txt
        $foundOn = new CrawlUri($this->getRobotsTxtUri($crawlUri), 1, true);

        foreach ($robotsTxt->getNonGroupDirectives()->getByField('sitemap')->getDirectives() as $directive) {
            try {
                $response = $this->escargot->getClient()->request('GET', $directive->getValue()->get());

                if (200 !== $response->getStatusCode()) {
                    continue;
                }

                $urls = new \SimpleXMLElement($response->getContent());

                foreach ($urls as $url) {
                    // Add it to the queue if not present already
                    $this->escargot->addUriToQueue(new Uri((string) $url->loc), $foundOn);
                }
            } catch (TransportExceptionInterface $exception) {
                continue;
            }
        }
    }
}
