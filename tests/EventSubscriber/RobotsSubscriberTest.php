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
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Event\PreRequestEvent;
use Terminal42\Escargot\Event\ResponseEvent;
use Terminal42\Escargot\EventSubscriber\RobotsSubscriber;
use Terminal42\Escargot\Queue\QueueInterface;

class RobotsSubscriberTest extends AbstractSubscriberTest
{
    public function testDoesNothingIfNoRobotsTxtIsProvided(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('log');

        $response = new MockResponse('', ['http_code' => 404]);
        $client = new MockHttpClient(function (string $method, string $uri) use ($response) {
            $this->assertSame('GET', $method);
            $this->assertSame('https://www.terminal.ch/robots.txt', $uri);

            return $response;
        });

        $escargot = $this->createEscargot($logger, null, $client);

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch'), 0));
        $subscriber = new RobotsSubscriber();
        $subscriber->onPreRequest($event);

        $this->assertFalse($event->wasRequestAborted());
    }

    public function testSitemapIsHandledCorrectly(): void
    {
        $robotsTxtResponse = new MockResponse("User-Agent: *\nDisallow:\n\nSitemap: https://www.terminal.ch/sitemap.xml", ['http_code' => 200]);
        $sitemapResponse = new MockResponse('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.terminal.ch/foobar</loc></url></urlset>', ['http_code' => 200]);

        $client = new MockHttpClient([$robotsTxtResponse, $sitemapResponse]);

        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo(''),
                $this->callback(function (Uri $uri) {
                    return 'https://www.terminal.ch/foobar' === (string) $uri;
                })
            );

        $escargot = $this->createEscargot(null, $queue, $client);

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch'), 0));
        $subscriber = new RobotsSubscriber();
        $subscriber->onPreRequest($event);

        $this->assertFalse($event->wasRequestAborted());
    }

    public function testRequestIsAbortedIfDisallowedByRobotsTxt(): void
    {
        $robotsTxtResponse = new MockResponse("User-Agent: *\nDisallow: /foobar", ['http_code' => 200]);

        $client = new MockHttpClient([$robotsTxtResponse]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(LogLevel::DEBUG),
                $this->equalTo('[URI: https://www.terminal.ch/foobar (Level: 0, Processed: no, Found on: root)] Will not crawl URI was disallowed by robots.txt!')
            );

        $escargot = $this->createEscargot($logger, null, $client);

        $event = new PreRequestEvent($escargot, new CrawlUri(new Uri('https://www.terminal.ch/foobar'), 0));
        $subscriber = new RobotsSubscriber();
        $subscriber->onPreRequest($event);

        $this->assertTrue($event->wasRequestAborted());
    }

    public function testResponseIsCanceledIfIndexingWasDisallowedByXRobotsTagHeader(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Early abort response as the X-Robots-Tag header contains "noindex".'
            );

        $chunk = $this->createMock(ChunkInterface::class);
        $chunk
            ->expects($this->once())
            ->method('isFirst')
            ->willReturn(true);

        $response = new MockResponse('', ['response_headers' => ['X-Robots-Tag' => ['noindex']]]);
        $client = new MockHttpClient([$response]);
        $response = $client->request('GET', 'https://www.terminal.ch', ['user_data' => new CrawlUri(new Uri('https://www.terminal.ch'), 0)]);

        $escargot = $this->createEscargot($logger, null, $client);

        $event = new ResponseEvent($escargot, $response, $chunk);

        $subscriber = new RobotsSubscriber();
        $subscriber->onResponse($event);

        $this->assertSame('Response has been canceled.', $response->getInfo('error'));
    }
}
