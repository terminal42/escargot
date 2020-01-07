<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2020, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Tests\Scenario;

use Symfony\Component\Finder\Finder;

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
    private $requests = [];

    /**
     * @var array
     */
    private $logs = [];

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

    public function getName(): string
    {
        return $this->name;
    }

    public function getArgumentsForCrawlProvider(): array
    {
        return [
            $this->getResponsesFactory(),
            $this->logs,
            $this->requests,
            $this->name.': '.$this->description,
            $this->options,
        ];
    }

    private function build(): void
    {
        $finder = new Finder();
        $finder->in($this->path)->files()->sortByName(true);

        foreach ($finder as $file) {
            if ('_requests' === $file->getBasename('.txt')) {
                $this->requests = array_filter(explode("\n", $file->getContents()));
                continue;
            }
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
        [$uri, $contents] = explode("\n\n", $contents."\n\n", 2);

        $this->responses[$uri] = MockResponseFactory::createFromString($contents);
    }

    /**
     * @return array
     */
    private function getResponsesFactory(): \Closure
    {
        return function ($method, $url, $options) {
            if (!isset($this->responses[$url])) {
                throw new \RuntimeException(sprintf('A request to URI "%s" would be executed but no MockResponse was provided!', $url));
            }

            return $this->responses[$url];
        };
    }
}
