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

use Psr\Http\Message\UriInterface;

final class BaseUriCollection implements \IteratorAggregate, \Countable
{
    /**
     * @var array<UriInterface>
     */
    private $baseUris = [];

    /**
     * @param array<UriInterface> $baseUris
     */
    public function __construct(array $baseUris = [])
    {
        foreach ($baseUris as $baseUri) {
            $this->add($baseUri);
        }
    }

    public function add(UriInterface $baseUri): self
    {
        $baseUri = CrawlUri::normalizeUri($baseUri);
        $this->baseUris[(string) $baseUri] = $baseUri;

        return $this;
    }

    public function contains(UriInterface $baseUri): bool
    {
        $baseUri = CrawlUri::normalizeUri($baseUri);

        return isset($this->baseUris[(string) $baseUri]);
    }

    public function containsHost(string $host): bool
    {
        $hosts = [];

        foreach ($this->baseUris as $baseUri) {
            $hosts[] = $baseUri->getHost();
        }

        return \in_array($host, $hosts, true);
    }

    public function mergeWith(self $collection): self
    {
        $merged = new self();

        foreach ($this as $baseUri) {
            $merged->add($baseUri);
        }

        foreach ($collection as $baseUri) {
            $merged->add($baseUri);
        }

        return $merged;
    }

    /**
     * @return array<UriInterface>
     */
    public function all(): array
    {
        return array_values($this->baseUris);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return \count($this->all());
    }
}
