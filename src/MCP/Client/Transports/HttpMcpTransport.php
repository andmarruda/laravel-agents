<?php

namespace Andmarruda\LaravelAgents\MCP\Client\Transports;

use RuntimeException;

class HttpMcpTransport implements McpTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected string $url,
        protected array $headers = [],
        protected int $timeout = 30,
    ) {
    }

    public function request(string $method, array $params = []): array
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => uniqid('mcp_', true),
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException('Unable to encode MCP request body.');
        }

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $this->formatHeaders($headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->url, false, $context);

        if ($response === false) {
            throw new RuntimeException("Unable to call MCP server [{$this->url}].");
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('MCP server returned invalid JSON.');
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? 'MCP server error.')
                : 'MCP server error.';

            throw new RuntimeException($message);
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : $decoded;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function formatHeaders(array $headers): string
    {
        return implode("\r\n", array_map(
            fn (string $name, string $value) => $name.': '.$value,
            array_keys($headers),
            $headers,
        ));
    }
}
