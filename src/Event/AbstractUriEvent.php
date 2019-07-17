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

use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\Escargot;

abstract class AbstractUriEvent extends AbstractEscargotEvent
{
    /**
     * @var UriInterface
     */
    private $uri;

    public function __construct(Escargot $crawler, UriInterface $uri)
    {
        parent::__construct($crawler);

        $this->uri = $uri;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }
}
