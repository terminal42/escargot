<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Subscriber;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ExceptionSubscriberInterface
{
    /**
     * Called if an HttpClient exception occurs during the crawl process.
     */
    public function onException(ExceptionInterface $exception, ResponseInterface $response): void;
}
