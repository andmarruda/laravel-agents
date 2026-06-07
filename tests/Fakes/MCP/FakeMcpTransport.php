<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes\MCP;

use Andmarruda\LaravelAgents\MCP\Client\Transports\McpTransport;

class FakeMcpTransport implements McpTransport
{
    /**
     * @var array<int, array{method: string, params: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @param array<string, array<string, mixed>> $responses
     */
    public function __construct(
        protected array $responses,
    ) {
    }

    public function request(string $method, array $params = []): array
    {
        $this->requests[] = compact('method', 'params');

        return $this->responses[$method] ?? [];
    }
}
