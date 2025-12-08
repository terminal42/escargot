<?php

declare(strict_types=1);

namespace Terminal42\Escargot;

use Nyholm\Psr7\Uri;

class HttpUriFactory
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function create(string $uri): Uri
    {
        if (!preg_match('#^https?://#i', $uri)) {
            throw new \InvalidArgumentException('Invalid HTTP URI.');
        }

        return new Uri($uri);
    }
}
