<?php

namespace Andmarruda\LaravelAgents\MCP\Client;

use Andmarruda\LaravelAgents\MCP\Client\Transports\McpTransport;

class McpClient
{
    public function __construct(
        protected McpTransport $transport,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function initialize(string $protocolVersion = '2025-06-18'): array
    {
        return $this->transport->request('initialize', [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'laravel-agents',
                'version' => '0.4',
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $result = $this->transport->request('tools/list');

        if (isset($result['tools']) && is_array($result['tools'])) {
            return $result['tools'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments = []): mixed
    {
        $result = $this->transport->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        return $result['structuredContent'] ?? $result['content'] ?? $result;
    }
}
