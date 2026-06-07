<?php

namespace Andmarruda\LaravelAgents\MCP\Client\Transports;

interface McpTransport
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array;
}
