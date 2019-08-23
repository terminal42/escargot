<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

use Psr\Http\Message\UriInterface;

class BaseUriCollection implements \IteratorAggregate
{
    /**
     * @var UriInterface[]
     */
    private $baseUris = [];

    /**
     * @param UriInterface[] $baseUris
     */
    public function __construct(array $baseUris = [])
    {
        foreach ($baseUris as $baseUri) {
            $this->add($baseUri);
        }
    }

    public function add(UriInterface $baseUri)
    {
        $baseUri = CrawlUri::normalizeUri($baseUri);
        $this->baseUris[(string) $baseUri] = $baseUri;
    }

    public function contains(UriInterface $baseUri): bool
    {
        $baseUri = CrawlUri::normalizeUri($baseUri);

        return isset($this->baseUris[(string) $baseUri]);
    }

    /**
     * @return UriInterface[]
     */
    public function all(): array
    {
        return array_values($this->baseUris);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->all());
    }
}
