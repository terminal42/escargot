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

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;

interface ExceptionSubscriberInterface
{
    /**
     * Called if a TransportException is thrown.
     */
    public function onTransportException(CrawlUri $crawlUri, TransportExceptionInterface $exception, ResponseInterface $response): void;

    /**
     * Called if a HttpException is thrown.
     */
    public function onHttpException(CrawlUri $crawlUri, HttpExceptionInterface $exception, ResponseInterface $response, ChunkInterface $chunk): void;
}
