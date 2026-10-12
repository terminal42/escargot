<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Subscriber;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;

interface ExceptionSubscriberInterface
{
    /**
     * Called if a TransportException is thrown.
     *
     * The response is null if the request failed before the client returned one.
     */
    public function onTransportException(CrawlUri $crawlUri, TransportExceptionInterface $exception, ResponseInterface|null $response): void;

    /**
     * Called if a HttpException is thrown.
     */
    public function onHttpException(CrawlUri $crawlUri, HttpExceptionInterface $exception, ResponseInterface $response, ChunkInterface $chunk): void;
}
