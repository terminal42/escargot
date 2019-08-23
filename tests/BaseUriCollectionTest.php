<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests;

use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Terminal42\Escargot\BaseUriCollection;

class BaseUriCollectionTest extends TestCase
{
    public function testAddingUrisWorks()
    {
        $uri1 = new Uri('https://terminal42.ch');
        $uri2 = new Uri('https://github.com');

        $collection = new BaseUriCollection([$uri1]);
        $this->assertCount(1, $collection);

        $collection->add($uri2);
        $this->assertCount(2, $collection);

        // Adding the same again should not have any influence
        $collection->add($uri2);
        $this->assertCount(2, $collection);
    }

    public function testContainsWorks()
    {
        $uri1 = new Uri('https://terminal42.ch');
        $uri2 = new Uri('https://github.com');
        $collection = new BaseUriCollection([$uri1]);

        $this->assertTrue($collection->contains($uri1));
        $this->assertFalse($collection->contains($uri2));
    }

    public function testMergeWorks()
    {
        $uri1 = new Uri('https://terminal42.ch');
        $uri2 = new Uri('https://github.com');

        $collection1 = new BaseUriCollection([$uri1]);
        $collection2 = new BaseUriCollection([$uri1, $uri2]);

        $merged = $collection1->mergeWith($collection2);

        $this->assertTrue($merged->contains($uri1));
        $this->assertTrue($merged->contains($uri2));
        $this->assertCount(2, $merged);
    }
}
