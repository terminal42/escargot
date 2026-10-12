<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests;

use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\HttpUriFactory;

final class HttpUriFactoryTest extends TestCase
{
    public function testThrowsOnInvalidHttpUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpUriFactory::create('http:/foobar.com');
    }
}
