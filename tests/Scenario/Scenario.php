<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Scenario;

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\Response\MockResponse;

class Scenario
{
    /**
     * @var array<string, MockResponse>|null
     */
    private array|null $responses = null;

    /**
     * @var array<string>
     */
    private array $requests = [];

    /**
     * @var array<string>
     */
    private array $logs = [];

    /**
     * @var array<string, string>
     */
    private array $options = [];

    private string $description = 'No scenario description given';

    /**
     * Scenario constructor.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {
        $this->build();
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array{\Closure(string, string, array<string, mixed>): MockResponse, array<string>, array<string>, string, array<string, string>}
     */
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
     * @return \Closure(string, string, array<string, mixed>): MockResponse
     */
    private function getResponsesFactory(): \Closure
    {
        return function (string $method, string $url, array $options): MockResponse {
            if (!isset($this->responses[$url])) {
                throw new \RuntimeException(\sprintf('A request to URI "%s" would be executed but no MockResponse was provided!', $url));
            }

            return $this->responses[$url];
        };
    }
}
