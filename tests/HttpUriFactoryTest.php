<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2022, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests;

use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\HttpUriFactory;

class HttpUriFactoryTest extends TestCase
{
    public function testThrowsOnInvalidHttpUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HttpUriFactory::create('http:/foobar.com');
    }
}
