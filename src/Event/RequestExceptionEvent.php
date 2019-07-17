<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Event;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\Escargot;

class RequestExceptionEvent extends AbstractEscargotEvent
{
    /**
     * @var ExceptionInterface
     */
    private $exception;

    /**
     * @var ResponseInterface|null
     */
    private $response;

    public function __construct(Escargot $crawler, ExceptionInterface $exception, ResponseInterface $response = null)
    {
        parent::__construct($crawler);

        $this->exception = $exception;
        $this->response = $response;
    }

    public function getException(): ExceptionInterface
    {
        return $this->exception;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
