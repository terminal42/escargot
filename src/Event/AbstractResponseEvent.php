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

use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Escargot;

abstract class AbstractResponseEvent extends AbstractEscargotEvent
{
    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct(Escargot $crawler, ResponseInterface $response)
    {
        parent::__construct($crawler);

        $this->response = $response;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
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
