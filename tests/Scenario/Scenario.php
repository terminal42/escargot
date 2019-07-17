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

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\Response\MockResponse;

class Scenario
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $path;

    /**
     * @var array
     */
    private $responses;

    /**
     * @var array
     */
    private $logs;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var string
     */
    private $description = 'No scenario description given';

    /**
     * Scenario constructor.
     */
    public function __construct(string $name, string $path)
    {
        $this->name = $name;
        $this->path = $path;

        $this->build();
    }

    public function getArgumentsForCrawlProvider(): array
    {
        return [
            $this->getResponsesFactory(),
            $this->logs,
            $this->name.': '.$this->description,
            $this->options,
        ];
    }

    private function build(): void
    {
        $finder = new Finder();
        $finder->in($this->path)->files()->sortByName(true);

        foreach ($finder as $file) {
            if ('_logs' === $file->getBasename('.txt')) {
                $this->logs = array_filter(explode("\n", $file->getContents()));
                continue;
            }
            if ('_description' === $file->getBasename('.txt')) {
                $this->description = $file->getContents();
                continue;
            }
            if ('_options' === $file->getBasename('.txt')) {
                $this->parseOptions($file->getContents());
                continue;
            }

            $this->parseResponse($file->getContents());
        }
    }

    private function parseOptions(string $contents): void
    {
        $lines = array_filter(explode("\n", $contents));

        foreach ($lines as $line) {
            [$k, $v] = explode(':', $line, 2);

            $this->options[trim($k)] = trim($v);
        }
    }

    private function parseResponse(string $contents): void
    {
        [$uri, $header, $body] = explode("\n\n", $contents."\n\n", 3);
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

        $this->responses[$uri] = new MockResponse($body, $info);
    }

    /**
     * @return array
     */
    private function getResponsesFactory(): \Closure
    {
        return function ($method, $url, $options) {
            if (!isset($this->responses[$url])) {
                throw new \RuntimeException(
                    sprintf('A request to URI "%s" would be executed but no MockResponse was provided!',
                    $url)
                );
            }

            return $this->responses[$url];
        };
    }
}
