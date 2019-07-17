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

class ExcludedByUriFilterEvent extends AbstractUriEvent
{
    /**
     * @var \DOMNode
     */
    private $node;

    public function __construct(Escargot $crawler, UriInterface $uri, \DOMNode $node)
    {
        parent::__construct($crawler, $uri);

        $this->node = $node;
    }

    public function getNode(): \DOMNode
    {
        return $this->node;
    }
}
