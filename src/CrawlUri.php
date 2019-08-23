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

class CrawlUri
{
    /**
     * @var UriInterface
     */
    private $uri;

    /**
     * @var int
     */
    private $level;

    /**
     * @var bool
     */
    private $processed = false;

    /**
     * @var bool
     */
    private $wasMarkedProcessed = false;

    /**
     * @var UriInterface|null
     */
    private $foundOn = null;

    public function __construct(UriInterface $uri, int $level, bool $processed = false, UriInterface $foundOn = null)
    {
        $this->uri = static::normalizeUri($uri);
        $this->level = $level;
        $this->processed = $processed;

        if (null !== $foundOn) {
            $this->foundOn = static::normalizeUri($foundOn);
        }
    }

    public function __toString()
    {
        return sprintf('URI: %s (Level: %d, Processed: %s, Found on: %s).',
            (string) $this->getUri(),
            $this->getLevel(),
            $this->isProcessed() ? 'yes' : 'no',
            (string) $this->getFoundOn() ?: 'root'
        );
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function markProcessed(): self
    {
        $this->processed = true;
        $this->wasMarkedProcessed = true;

        return $this;
    }

    public function wasMarkedProcessed(): bool
    {
        return $this->wasMarkedProcessed;
    }

    public function getFoundOn(): ?UriInterface
    {
        return $this->foundOn;
    }

    public static function normalizeUri(UriInterface $uri): UriInterface
    {
        if ('' === $uri->getScheme()) {
            $uri = $uri->withScheme('http');
        }

        if ('' === $uri->getPath()) {
            $uri = $uri->withPath('/');
        }

        return $uri;
    }
}
