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

final class CrawlUri implements \Stringable
{
    private readonly UriInterface $uri;

    private bool $wasMarkedProcessed = false;

    private UriInterface|null $foundOn = null;

    private array $tags = [];

    public function __construct(
        UriInterface $uri,
        private readonly int $level,
        private bool $processed = false,
        UriInterface|null $foundOn = null,
    ) {
        $this->uri = self::normalizeUri($uri);

        if (null !== $foundOn) {
            $this->foundOn = self::normalizeUri($foundOn);
        }
    }

    public function __toString(): string
    {
        return sprintf('URI: %s (Level: %d, Processed: %s, Found on: %s, Tags: %s)',
            (string) $this->getUri(),
            $this->getLevel(),
            $this->isProcessed() ? 'yes' : 'no',
            (string) ($this->getFoundOn() ?: 'root'),
            $this->getTags() ? implode(', ', $this->getTags()) : 'none',
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

    public function getFoundOn(): UriInterface|null
    {
        return $this->foundOn;
    }

    public function getTags(): array
    {
        return array_keys($this->tags);
    }

    public function addTag(string $tag): self
    {
        if (str_contains($tag, ',')) {
            throw new \InvalidArgumentException('Cannot use commas in tags.');
        }

        $this->tags[$tag] = true;

        return $this;
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    public function removeTag(string $tag): self
    {
        unset($this->tags[$tag]);

        return $this;
    }

    public static function normalizeUri(UriInterface $uri): UriInterface
    {
        if ('' === $uri->getScheme()) {
            $uri = $uri->withScheme('http');
        }

        if ('' === $uri->getPath()) {
            $uri = $uri->withPath('/');
        }

        return $uri->withFragment('');
    }
}
