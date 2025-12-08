<?php

declare(strict_types=1);

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
