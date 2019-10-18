<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\EventSubscriber;

use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Event\ResponseEvent;
use Terminal42\Escargot\EventSubscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Tests\Scenario\MockResponseFactory;

class HtmlCrawlerSubscriberTest extends AbstractSubscriberTest
{
    /**
     * @dataProvider htmlIsProcessedCorrectlyProvider
     */
    public function testHtmlIsProcessedCorrectly(string $response, array $expectedLogEntries, array $expectedUrisToAddToTheQueue = []): void
    {
        $logExpectations = [];

        foreach ($expectedLogEntries as $expectedLogEntry) {
            $logExpectations[] = [$this->equalTo(LogLevel::DEBUG), $this->equalTo($expectedLogEntry)];
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->exactly(\count($logExpectations)))
            ->method('log')
            ->withConsecutive(...$logExpectations);

        $queue = new InMemoryQueue();

        if (0 !== \count($expectedUrisToAddToTheQueue)) {
            $addToQueueExpectations = [];

            foreach ($expectedUrisToAddToTheQueue as $expectedUri) {
                $addToQueueExpectations[] = [
                    $this->equalTo(''),
                    $this->callback(function (Uri $uri) use ($expectedUri) {
                        return $expectedUri === (string) $uri;
                    }),
                ];
            }

            $queue = $this->createMock(QueueInterface::class);
            $queue
                ->expects($this->exactly(\count($addToQueueExpectations)))
                ->method('get')
                ->withConsecutive(...$addToQueueExpectations);
        }

        $escargot = $this->createEscargot($logger, $queue);

        $chunk = $this->createMock(ChunkInterface::class);
        $chunk
            ->expects($this->once())
            ->method('isLast')
            ->willReturn(true);

        $response = MockResponseFactory::createFromString($response);
        $client = new MockHttpClient([$response]);
        $response = $client->request('GET', 'https://www.terminal.ch', ['user_data' => new CrawlUri(new Uri('https://www.terminal.ch'), 0)]);

        $event = new ResponseEvent($escargot, $response, $chunk);

        $subscriber = new HtmlCrawlerSubscriber();
        $subscriber->onResponse($event);
    }

    public function htmlIsProcessedCorrectlyProvider(): \Generator
    {
        yield '204 with no content' => [
            <<<'EOT'
HTTP/2.0 204 No Content
content-type: text/html; charset=UTF-8
EOT
            ,
            ['[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped this URI because it did not contain any content (or was indicated so by the 204 status code).'],
        ];

        yield '204 with content (so an incorrect response)' => [
            <<<'EOT'
HTTP/2.0 204 No Content
content-type: text/html; charset=UTF-8

body
EOT
            ,
            ['[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped this URI because it did not contain any content (or was indicated so by the 204 status code).'],
        ];

        yield 'X-Robots-Tag header contains nofollow' => [
            <<<'EOT'
HTTP/2.0 200 OK
X-Robots-Tag: noindex, nofollow

body
EOT
            ,
            ['[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped all links on this URI because the X-Robots-Tag contained "nofollow".'],
        ];

        yield '<meta name="robots"> tag contains nofollow' => [
            <<<'EOT'
HTTP/2.0 200 OK
Content-Type: text/html

<html>
<head>
    <meta name="robots" content="nofollow">
</head>
<body>
    <a href="https://www.terminal42.ch/foobar">
</body>
</html>
EOT
            ,
            ['[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped all links on this URI because the <meta name="robots"> tag contained "nofollow".'],
        ];

        yield 'Skip non http(s) scheme URIs' => [
            <<<'EOT'
HTTP/2.0 200 OK
Content-Type: text/html

<html>
<head>
</head>
<body>
    <a href="tel:0123456789">
    <a href="geo:37.786971,-122.399677;u=35">
    <a href="https://www.terminal42.ch/foobar">
</body>
</html>
EOT
            ,
            [
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "tel:0123456789" because it does not start with http(s).',
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "geo:37.786971,-122.399677;u=35" because it does not start with http(s).',
            ],
            [
                'https://www.terminal42.ch/foobar',
            ],
        ];

        yield 'Skip rel="nofollow" links' => [
            <<<'EOT'
HTTP/2.0 200 OK
Content-Type: text/html

<html>
<head>
</head>
<body>
    <a href="https://www.terminal42.ch/foobar" rel="nofollow">
    <a href="https://www.terminal42.ch/foobar2" rel="nofollow">
    <a href="https://www.terminal42.ch/foobar3">
</body>
</html>
EOT
            ,
            [
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "https://www.terminal42.ch/foobar" because the "rel" attribute contains "nofollow".',
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "https://www.terminal42.ch/foobar2" because the "rel" attribute contains "nofollow".',
            ],
            [
                'https://www.terminal42.ch/foobar3',
            ],
        ];

        yield 'Skip non type="text/html" links' => [
            <<<'EOT'
HTTP/2.0 200 OK
Content-Type: text/html

<html>
<head>
</head>
<body>
    <a href="https://www.terminal42.ch/foobar.pdf" type="application/pdf">
    <a href="https://www.terminal42.ch/foobar2">
    <a href="https://www.terminal42.ch/foobar3" type="text/html">
        <a href="https://www.terminal42.ch/download.png" type="image/png">

</body>
</html>
EOT
            ,
            [
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "https://www.terminal42.ch/foobar.pdf" because the "type" attribute does not contain "text/html".',
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "https://www.terminal42.ch/download.png" because the "type" attribute does not contain "text/html".',
            ],
            [
                'https://www.terminal42.ch/foobar2',
                'https://www.terminal42.ch/foobar3',
            ],
        ];

        yield 'Skip hosts not present in base uri collection' => [
            <<<'EOT'
HTTP/2.0 200 OK
Content-Type: text/html

<html>
<head>
</head>
<body>
    <a href="https://www.terminal42.ch/foobar1">
    <a href="https://www.terminal42.ch/foobar2">
    <a href="https://github.com/foobar">
    <a href="https://www.terminal42.ch/foobar3" type="text/html">
</body>
</html>
EOT
            ,
            [
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Skipped URI "https://github.com/foobar" because the host is not allowed by the base URI collection.',
            ],
            [
                'https://www.terminal42.ch/foobar1',
                'https://www.terminal42.ch/foobar2',
                'https://www.terminal42.ch/foobar3',
            ],
        ];
    }
}
