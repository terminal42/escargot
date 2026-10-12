<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Subscriber;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;
use Terminal42\Escargot\Subscriber\Util;

class UtilTest extends TestCase
{
    #[DataProvider('isAllowedToFollowProvider')]
    public function testIsAllowedToFollow(CrawlUri $crawlUri, Escargot $escargot, bool $expected): void
    {
        $this->assertSame($expected, Util::isAllowedToFollow($crawlUri, $escargot));
    }

    /**
     * @return iterable<array{CrawlUri, Escargot, bool}>
     */
    public static function isAllowedToFollowProvider(): iterable
    {
        yield 'Found on an URI that should not be followed according to the x-robots-tag header or <meta name="robots"> information' => [
            new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')),
            self::createEscargotWithFoundOnUri(
                (new CrawlUri(new Uri('https://www.terminal42.ch'), 0, true))->addTag(RobotsSubscriber::TAG_NOFOLLOW),
            ),
            false,
        ];

        yield 'Current URI was disallowed by robots.txt' => [
            (new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')))->addTag(RobotsSubscriber::TAG_DISALLOWED_ROBOTS_TXT),
            self::createEscargot(),
            false,
        ];

        yield 'Current URI can be followed' => [
            new CrawlUri(new Uri('https://www.terminal42.ch/foobar'), 1, false, new Uri('https://www.terminal42.ch')),
            self::createEscargot(),
            true,
        ];
    }

    private static function createEscargotWithFoundOnUri(CrawlUri $foundOn): Escargot
    {
        $escargot = self::createEscargot();
        $escargot->getQueue()->add($escargot->getJobId(), $foundOn);

        return $escargot;
    }

    private static function createEscargot(): Escargot
    {
        return Escargot::create(new BaseUriCollection([new Uri('https://www.terminal42.ch')]), new InMemoryQueue());
    }
}
