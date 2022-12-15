<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2022, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Subscriber;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\Util;

class UtilTest extends TestCase
{
    /**
     * @dataProvider isAllowedToFollowProvider
     */
    public function testIsAllowedToFollow(CrawlUri $crawlUri, Escargot $escargot, bool $expected): void
    {
        $this->assertSame($expected, Util::isAllowedToFollow($crawlUri, $escargot));
    }

    public function isAllowedToFollowProvider(): \Generator
    {
        yield 'Found on an URI that should not be followed according to the x-robots-tag header or <meta name="robots"> information' => [
            new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')),
            $this->createEscargotWithFoundOnUri(
                (new CrawlUri(new Uri('https://www.terminal42.ch'), 0, true))->addTag(RobotsSubscriber::TAG_NOFOLLOW)
            ),
            false,
        ];

        yield 'Current URI was disallowed by robots.txt' => [
            (new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')))->addTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT),
            $this->createEscargot(),
            false,
        ];

        yield 'Current URI can be followed' => [
            new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')),
            $this->createEscargot(),
            true,
        ];
    }

    private function createEscargotWithFoundOnUri(CrawlUri $foundOn): Escargot
    {
        $escargot = $this->createEscargot();
        $escargot->getQueue()->add($escargot->getJobId(), $foundOn);

        return $escargot;
    }

    private function createEscargot(): Escargot
    {
        return Escargot::create(new BaseUriCollection([new Uri('https://www.terminal42.ch')]), new InMemoryQueue());
    }
}
