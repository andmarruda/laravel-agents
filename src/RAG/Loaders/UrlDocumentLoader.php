<?php

namespace Andmarruda\LaravelAgents\RAG\Loaders;

use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

class UrlDocumentLoader implements DocumentLoader
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $allowedHosts
     */
    public function __construct(
        protected string $url,
        protected array $metadata = [],
        protected ?Factory $http = null,
        protected int $timeout = 30,
        protected array $allowedHosts = [],
        protected int $maxBytes = 10_485_760,
    ) {
        if ($timeout < 1) {
            throw new InvalidArgumentException('Document URL timeout must be at least 1 second.');
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Document URL byte limit must be at least 1.');
        }
    }

    public function load(): array
    {
        if (! filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Document URL [{$this->url}] is invalid.");
        }

        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $allowedHosts = array_map('strtolower', $this->allowedHosts);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Document URL scheme [{$scheme}] is not allowed.");
        }

        if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException("Document URL host [{$host}] is not allowed.");
        }

        $http = $this->http ?? app(Factory::class);
        $response = $http->timeout($this->timeout)->withoutRedirecting()->get($this->url)->throw();
        $mimeType = trim(explode(';', $response->header('Content-Type') ?: 'text/plain')[0]);
        $content = $response->body();

        if (strlen($content) > $this->maxBytes) {
            throw new RuntimeException("Document URL [{$this->url}] exceeds the {$this->maxBytes} byte limit.");
        }

        return [Document::fromText($content, $this->metadata, $this->url, $mimeType)];
    }
}
