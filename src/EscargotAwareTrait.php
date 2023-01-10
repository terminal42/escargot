<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2023, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

trait EscargotAwareTrait
{
    /**
     * @var Escargot
     */
    private $escargot;

    public function getEscargot(): Escargot
    {
        return $this->escargot;
    }

    public function setEscargot(Escargot $escargot): void
    {
        $this->escargot = $escargot;
    }
}
