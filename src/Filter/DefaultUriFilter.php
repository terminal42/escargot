<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Filter;

use Psr\Http\Message\UriInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Terminal42\Escargot\Escargot;
use webignition\RobotsTxt\File\Parser;
use webignition\RobotsTxt\Inspector\Inspector;

/**
 * Only crawls links that fulfil the following requirements.
 *
 * - Either http or https schema
 * - The node does not have rel="nofollow" set
 * - The node does not have the type attribute set or it is set and the value equals to "text/html"
 * - The URI is allowed by the configured allowed hosts (by default just the same host as the base URI)
 * - The URI is allowed by the robots.txt of that URI
 */
class DefaultUriFilter implements UriFilterInterface
{
    /**
     * @var Escargot
     */
    private $crawler;

    /**
     * The user agent for robots.txt matching.
     *
     * @var string
     */
    private $userAgent;

    /**
     * The hosts that are allowed and won't be filtered.
     * By default only the host of the base URI is allowed.
     *
     * @var array
     */
    private $allowedHosts;

    /**
     * @var Inspector[]
     */
    private $robotsTxtInspectors;

    public function __construct(Escargot $crawler, string $userAgent, array $allowedHosts = [])
    {
        $this->crawler = $crawler;
        $this->userAgent = $userAgent;

        if (0 === \count($allowedHosts)) {
            $this->allowedHosts = [$crawler->getBaseUri()->getHost()];
        }
    }

    public function shouldCrawl(UriInterface $uri, \DOMElement $node): bool
    {
        // Skip non http URIs
        if (!\in_array($uri->getScheme(), ['http', 'https'], true)) {
            return false;
        }

        // Skip rel="nofollow" links
        if ('nofollow' === $node->getAttribute('rel')) {
            return false;
        }

        // Skip the links that have the "type" attribute set and it's not text/html
        if (($type = $node->getAttribute('type')) && 'text/html' !== $type) {
            return false;
        }

        // Only crawl URIs of the same host
        if (!$this->checkHost($uri)) {
            return false;
        }

        // Check if target is allowed by robots.txt
        if (!$this->isAllowedByRobotsTxt($uri)) {
            return false;
        }

        return true;
    }

    protected function checkHost(UriInterface $uri): bool
    {
        return \in_array($uri->getHost(), $this->allowedHosts, true);
    }

    private function isAllowedByRobotsTxt(UriInterface $uri): bool
    {
        if (!isset($this->robotsTxtInspectors[$uri->getHost()])) {
            $this->robotsTxtInspectors[$uri->getHost()] = $this->loadRobotsTxtForUri($uri);
        }

        // Everything is allowed if there's no robots.txt
        if (!$this->robotsTxtInspectors[$uri->getHost()] instanceof Inspector) {
            return true;
        }

        return $this->robotsTxtInspectors[$uri->getHost()]->isAllowed($uri->getPath());
    }

    /**
     * @return Inspector|false An inspector instance if the robots.txt was found or false
     */
    private function loadRobotsTxtForUri(UriInterface $uri)
    {
        $robotsTxtUri = $uri->withPath('/robots.txt');

        try {
            $response = $this->crawler->getClient()->request('GET', (string) $robotsTxtUri);

            if (200 === $response->getStatusCode()) {
                $parser = new Parser();
                $parser->setSource($response->getContent());

                $inspector = new Inspector($parser->getFile());
                $inspector->setUserAgent($this->userAgent);

                return $inspector;
            }

            return false;
        } catch (TransportExceptionInterface $e) {
            return false;
        }
    }
}
