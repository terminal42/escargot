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
     * @var UriInterface|null
     */
    private $foundOn;

    public function __construct(UriInterface $uri, int $level, UriInterface $foundOn = null)
    {
        $this->uri = $uri;
        $this->level = $level;
        $this->foundOn = $foundOn;
    }

    public function __toString()
    {
        return sprintf('URI: %s (Level: %d - Found on %s).',
            (string) $this->getUri(),
            $this->getLevel(),
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

    public function getFoundOn(): ?UriInterface
    {
        return $this->foundOn;
    }
}
