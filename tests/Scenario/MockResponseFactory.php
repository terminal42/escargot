<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Scenario;

use Symfony\Component\HttpClient\Response\MockResponse;

class MockResponseFactory
{
    public static function createFromString(string $contents): MockResponse
    {
        [$header, $body] = explode("\n\n", $contents."\n\n", 2);
        $headers = explode("\n", $header);

        $info = [];

        // Status code
        $statusLine = array_shift($headers);
        $info['http_code'] = (int) substr($statusLine, 9, 3);

        $mappedHeaders = [];

        foreach ($headers as $headerLine) {
            [$k, $v] = explode(':', $headerLine);
            $mappedHeaders[strtolower($k)][] = $v;
        }

        $info['response_headers'] = $mappedHeaders;

        return new MockResponse($body, $info);
    }
}
