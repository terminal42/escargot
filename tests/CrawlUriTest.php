<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\CrawlUri;

final class CrawlUriTest extends TestCase
{
    public function testRedirectDestinationIsNormalizedAndDoesNotChangeOriginalUri(): void
    {
        $crawlUri = new CrawlUri(new Uri('https://example.com/original'), 0);
        $this->assertNull($crawlUri->getRedirectedTo());

        $this->assertSame($crawlUri, $crawlUri->markRedirectedTo(new Uri('https://example.org#fragment')));

        $this->assertSame('https://example.org/', (string) $crawlUri->getRedirectedTo());
        $this->assertSame('https://example.com/original', (string) $crawlUri->getUri());
        $this->assertSame(
            'URI: https://example.com/original (Level: 0, Processed: no, Found on: root, Redirected to: https://example.org/, Tags: none)',
            (string) $crawlUri,
        );

        $crawlUri->markRedirectedTo(null);

        $this->assertNull($crawlUri->getRedirectedTo());
        $this->assertSame('URI: https://example.com/original (Level: 0, Processed: no, Found on: root, Tags: none)', (string) $crawlUri);
    }
}
