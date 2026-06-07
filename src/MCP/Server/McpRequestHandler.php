<?php

namespace Andmarruda\LaravelAgents\MCP\Server;

class McpRequestHandler
{
    public function __construct(
        protected McpServer $server,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|array<int, array<string, mixed>>|null
     */
    public function handle(array $payload, mixed $request = null): array|null
    {
        if (array_is_list($payload)) {
            return $this->server->handleBatch($payload, $request);
        }

        return $this->server->handle($payload, $request);
    }
}
