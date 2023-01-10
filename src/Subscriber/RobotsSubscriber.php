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

use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\EscargotAwareInterface;
use Terminal42\Escargot\EscargotAwareTrait;
use Terminal42\Escargot\HttpUriFactory;
use Terminal42\Escargot\SubscriberLoggerTrait;
use webignition\RobotsTxt\File\File;
use webignition\RobotsTxt\File\Parser;
use webignition\RobotsTxt\Inspector\Inspector;

final class RobotsSubscriber implements SubscriberInterface, EscargotAwareInterface, LoggerAwareInterface
{
    use EscargotAwareTrait;
    use LoggerAwareTrait;
    use SubscriberLoggerTrait;

    public const TAG_NOINDEX = 'noindex';
    public const TAG_NOFOLLOW = 'nofollow';
    public const TAG_DISALLOWED_ROBOTS_TXT = 'disallowed-robots-txt';
    public const TAG_IS_SITEMAP = 'is-sitemap';

    /**
     * @var array<string,File>
     */
    private $robotsTxtCache = [];

    /**
     * {@inheritdoc}
     */
    public function shouldRequest(CrawlUri $crawlUri): string
    {
        // Check if it is a sitemap previously found
        if ($crawlUri->hasTag(self::TAG_IS_SITEMAP)) {
            return self::DECISION_POSITIVE;
        }

        // Add robots.txt information
        $this->handleDisallowedByRobotsTxtTag($crawlUri);

        // We don't care if the URI is going to be crawled or not, that's up to other subscribers
        return self::DECISION_ABSTAIN;
    }

    /**
     * {@inheritdoc}
     */
    public function needsContent(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): string
    {
        // Check if it is a sitemap previously found
        if ($crawlUri->hasTag(self::TAG_IS_SITEMAP)) {
            return self::DECISION_POSITIVE;
        }

        // Add tags
        if (\in_array('x-robots-tag', array_keys($response->getHeaders()), true)) {
            $xRobotsTagValue = $response->getHeaders()['x-robots-tag'][0];
            $this->handleNoindexNofollowTags(
                $crawlUri,
                $xRobotsTagValue,
                'Added the "%tag%" tag because the X-Robots-Tag header contained "%value%".'
            );
        }

        // We don't care if the rest of the content is loaded
        return self::DECISION_ABSTAIN;
    }

    public function onLastChunk(CrawlUri $crawlUri, ResponseInterface $response, ChunkInterface $chunk): void
    {
        // Check if it is a sitemap previously found
        if ($crawlUri->hasTag(self::TAG_IS_SITEMAP)) {
            $this->extractUrisFromSitemap($crawlUri, $response->getContent());

            return;
        }

        // We don't care about non HTML responses
        if (!Util::isOfContentType($response, 'text/html')) {
            return;
        }

        $crawler = new Crawler($response->getContent());
        $metaCrawler = $crawler->filterXPath('descendant-or-self::head/descendant-or-self::*/meta[@name = \'robots\'][@content]');
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

            $this->logWithCrawlUri(
                $crawlUri,
                LogLevel::DEBUG,
                str_replace(['%value%', '%tag%'], [$value, $tag], $messageTpl)
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

        if (!$inspector->isAllowed($this->getPathAndQuery($crawlUri->getUri()))) {
            $crawlUri->addTag(self::TAG_DISALLOWED_ROBOTS_TXT);

            $this->logWithCrawlUri(
                $crawlUri,
                LogLevel::DEBUG,
                sprintf(
                    'Added the "%s" tag because of the robots.txt content.',
                    self::TAG_DISALLOWED_ROBOTS_TXT
                )
            );
        }
    }

    private function getRobotsTxtFile(CrawlUri $crawlUri): ?File
    {
        $robotsTxtUri = $this->getRobotsTxtUri($crawlUri);

        // Check the cache
        if (\array_key_exists((string) $robotsTxtUri, $this->robotsTxtCache)) {
            return $this->robotsTxtCache[(string) $robotsTxtUri];
        }

        try {
            $response = $this->escargot->getClient()->request('GET', (string) $robotsTxtUri);

            try {
                $robotsTxtContent = $response->getContent();
            } catch (HttpExceptionInterface $e) {
                return $this->robotsTxtCache[(string) $robotsTxtUri] = null;
            }

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
        // If we hit max depth level, we don't even need to do anything because we cannot add anything to the queue
        // anymore (shouldn't happen because we're on level 0 here always)
        if ($this->escargot->isMaxDepthReached($crawlUri)) {
            return;
        }

        // The robots.txt is always level 1
        $foundOnRobotsTxt = new CrawlUri($this->getRobotsTxtUri($crawlUri), 1, true);

        foreach ($robotsTxt->getNonGroupDirectives()->getByField('sitemap')->getDirectives() as $directive) {
            try {
                $sitemapUri = HttpUriFactory::create($directive->getValue()->get());
            } catch (\InvalidArgumentException $e) {
                $this->logWithCrawlUri(
                    $crawlUri,
                    LogLevel::DEBUG,
                    sprintf(
                        'Could not add sitemap URI "%s" to the queue because the URI is invalid.',
                        $directive->getValue()->get()
                    )
                );
                continue;
            }

            // Normalize uri
            $sitemapUri = CrawlUri::normalizeUri($sitemapUri);

            // Add to queue and mark as being a sitemap
            $newCrawlUri = $this->escargot->addUriToQueue($sitemapUri, $foundOnRobotsTxt);
            $newCrawlUri->addTag(self::TAG_IS_SITEMAP);
        }
    }

    private function extractUrisFromSitemap(CrawlUri $sitemapUri, string $content): void
    {
        // If we hit max depth level, we don't even need to do anything because we cannot add anything to the queue
        // anymore
        if ($this->escargot->isMaxDepthReached($sitemapUri)) {
            return;
        }

        set_error_handler(function ($errno, $errstr): void {
            throw new \Exception($errstr, $errno);
        });
        try {
            $urls = new \SimpleXMLElement($content);
        } catch (\Exception $exception) {
            return;
        } finally {
            restore_error_handler();
        }

        $sitemapIndex = ('sitemapindex' === $urls->getName());

        foreach ($urls as $url) {
            // Add it to the queue if not present already
            try {
                $uri = HttpUriFactory::create((string) $url->loc);
            } catch (\InvalidArgumentException $e) {
                $this->logWithCrawlUri(
                    $sitemapUri,
                    LogLevel::DEBUG,
                    sprintf(
                        'Could not add URI "%s" found on in the sitemap to the queue because the URI is invalid.',
                        (string) $url->loc
                    )
                );

                continue;
            }

            $crawlUrl = $this->escargot->addUriToQueue($uri, $sitemapUri);
            if ($sitemapIndex) {
                $crawlUrl->addTag(self::TAG_IS_SITEMAP);
            }
        }
    }

    private function getPathAndQuery(UriInterface $uri): string
    {
        $path = $uri->getPath();

        if ($query = $uri->getQuery()) {
            $path .= '?'.$query;
        }

        return $path;
    }
}
