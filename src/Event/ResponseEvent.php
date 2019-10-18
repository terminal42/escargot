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

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;

class ResponseEvent extends AbstractEscargotEvent
{
    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @var ChunkInterface
     */
    private $currentChunk;

    public function __construct(Escargot $crawler, ResponseInterface $response, ChunkInterface $currentChunk)
    {
        parent::__construct($crawler);

        $this->response = $response;
        $this->currentChunk = $currentChunk;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getCurrentChunk(): ChunkInterface
    {
        return $this->currentChunk;
    }

    public function responseWasCanceled(): bool
    {
        // Symfony 4.4+
        $canceled = $this->response->getInfo('canceled');
        if (\is_bool($canceled)) {
            return $canceled;
        }

        return 'Response has been canceled.' === $this->response->getInfo('error');
    }

    public function getCrawlUri(): CrawlUri
    {
        $uri = $this->getResponse()->getInfo('user_data');

        if (!$uri instanceof CrawlUri) {
            throw new \RuntimeException('When executing a request, you have to make sure to pass the CrawlUri instance as "user_data"!');
        }

        return $uri;
    }
}
