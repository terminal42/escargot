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

use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;

class Util
{
    public static function isOfContentType(ResponseInterface $response, string $contentType): bool
    {
        if (!\in_array('content-type', array_keys($response->getHeaders()), true)) {
            return false;
        }

        return false !== strpos($response->getHeaders()['content-type'][0], $contentType);
    }

    /**
     * Helper method to quickly decide whether an URI may be requested based on default HTML crawler
     * standards:.
     *
     * - Returns false if the current CrawlUri was found on an URI that either contained "nofollow" in the X-Robots-Tag
     *   HTTP header or the <meta name="robots"> HTML tag.
     * - Returns false if the current CrawlUri was disallowed by the robots.txt content.
     * - Returns false if the current CrawlUri has the rel="nofollow" attribute.
     */
    public static function isAllowedToFollow(CrawlUri $crawlUri, Escargot $escargot): bool
    {
        // Check the original crawlUri to see if that one contained nofollow information
        if (null !== $crawlUri->getFoundOn() && ($originalCrawlUri = $escargot->getCrawlUri($crawlUri->getFoundOn()))) {
            if ($originalCrawlUri->hasTag(RobotsSubscriber::TAG_NOFOLLOW)) {
                return false;
            }
        }

        // Skip links that were disallowed by the robots.txt
        if ($crawlUri->hasTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT)) {
            return false;
        }

        return true;
    }
}
