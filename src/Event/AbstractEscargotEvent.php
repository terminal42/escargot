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

use Symfony\Contracts\EventDispatcher\Event;
use Terminal42\Escargot\Escargot;

abstract class AbstractEscargotEvent extends Event
{
    /**
     * @var Escargot
     */
    private $escargot;

    public function __construct(Escargot $escargot)
    {
        $this->escargot = $escargot;
    }

    public function getEscargot(): Escargot
    {
        return $this->escargot;
    }
}
