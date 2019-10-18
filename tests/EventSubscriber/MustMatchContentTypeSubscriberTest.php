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
use Terminal42\Escargot\Event\ResponseEvent;
use Terminal42\Escargot\EventSubscriber\MustMatchContentTypeSubscriber;

class MustMatchContentTypeSubscriberTest extends AbstractSubscriberTest
{
    public function testResponseIsCanceledWhenNoContentTypeWasProvided(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Early abort response as the Content-Type header does not match (expected: "text/html" got: "none provided").'
            );

        $response = new MockResponse();
        $client = new MockHttpClient([$response]);
        $response = $client->request('GET', 'https://www.terminal.ch', ['user_data' => new CrawlUri(new Uri('https://www.terminal.ch'), 0)]);

        $escargot = $this->createEscargot($logger, null, $client);

        $event = new ResponseEvent($escargot, $response, $this->createMock(ChunkInterface::class));

        $subscriber = new MustMatchContentTypeSubscriber('text/html');
        $subscriber->onResponse($event);

        $this->assertSame('Response has been canceled.', $response->getInfo('error'));
    }

    public function testResponseIsCanceledWhenContentTypeDoesNotMatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::DEBUG,
                '[URI: https://www.terminal.ch/ (Level: 0, Processed: no, Found on: root)] Early abort response as the Content-Type header does not match (expected: "text/html" got: "application/xml").'
            );

        $response = new MockResponse('', ['response_headers' => ['Content-Type' => ['application/xml']]]);
        $client = new MockHttpClient([$response]);
        $response = $client->request('GET', 'https://www.terminal.ch', ['user_data' => new CrawlUri(new Uri('https://www.terminal.ch'), 0)]);

        $escargot = $this->createEscargot($logger);

        $event = new ResponseEvent($escargot, $response, $this->createMock(ChunkInterface::class));

        $subscriber = new MustMatchContentTypeSubscriber('text/html');
        $subscriber->onResponse($event);

        $this->assertSame('Response has been canceled.', $response->getInfo('error'));
    }

    public function testResponseIsNotCanceledWhenTheContentTypeHeaderMatches(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('log');

        $escargot = $this->createEscargot($logger);

        $response = new MockResponse('', ['response_headers' => ['Content-Type' => ['text/html']]]);
        $client = new MockHttpClient([$response]);
        $response = $client->request('GET', 'https://www.terminal.ch', ['user_data' => new CrawlUri(new Uri('https://www.terminal.ch'), 0)]);

        $event = new ResponseEvent($escargot, $response, $this->createMock(ChunkInterface::class));

        $subscriber = new MustMatchContentTypeSubscriber('text/html');
        $subscriber->onResponse($event);

        $this->assertNull($response->getInfo('error'));
    }
}
