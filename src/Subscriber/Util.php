<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2020, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Subscriber;

use Symfony\Contracts\HttpClient\ResponseInterface;

class Util
{
    public static function isOfContentType(ResponseInterface $response, string $contentType): bool
    {
        if (!\in_array('content-type', array_keys($response->getHeaders()), true)) {
            return false;
        }

        return false !== strpos($response->getHeaders()['content-type'][0], $contentType);
    }
}
