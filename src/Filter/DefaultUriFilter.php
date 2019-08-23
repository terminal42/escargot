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
use Terminal42\Escargot\Escargot;

/**
 * Only crawls links that fulfil the following requirements.
 *
 * - Either http or https schema
 * - The node does not have rel="nofollow" set
 * - The node does not have the type attribute set or it is set and the value equals to "text/html"
 * - The URI is allowed by the configured allowed hosts (by default just the same host as the base URI)
 */
class DefaultUriFilter implements UriFilterInterface
{
    /**
     * @var Escargot
     */
    private $crawler;

    /**
     * The hosts that are allowed and won't be filtered.
     * By default only the host of the base URI is allowed.
     *
     * @var array
     */
    private $allowedHosts;

    public function __construct(Escargot $crawler, array $allowedHosts = [])
    {
        $this->crawler = $crawler;

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

        return true;
    }

    protected function checkHost(UriInterface $uri): bool
    {
        return \in_array($uri->getHost(), $this->allowedHosts, true);
    }
}
