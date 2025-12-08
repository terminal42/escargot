<?php

declare(strict_types=1);

namespace Terminal42\Escargot;

interface EscargotAwareInterface
{
    public function setEscargot(Escargot $escargot): void;
}
