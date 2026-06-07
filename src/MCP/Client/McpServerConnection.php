<?php

namespace Andmarruda\LaravelAgents\MCP\Client;

use Andmarruda\LaravelAgents\MCP\Client\Transports\HttpMcpTransport;
use Andmarruda\LaravelAgents\MCP\Client\Transports\StdioMcpTransport;
use Andmarruda\LaravelAgents\MCP\Data\McpServerDefinition;
use InvalidArgumentException;

class McpServerConnection
{
    public function connect(McpServerDefinition $definition): McpClient
    {
        if ($definition->transport === 'http') {
            if ($definition->url === null || $definition->url === '') {
                throw new InvalidArgumentException("MCP server [{$definition->name}] is missing a URL.");
            }

            return new McpClient(new HttpMcpTransport($definition->url, $definition->headers));
        }

        if ($definition->transport === 'stdio') {
            $command = $definition->extra['command'] ?? null;

            if (! is_array($command)) {
                throw new InvalidArgumentException("MCP stdio server [{$definition->name}] is missing a command array.");
            }

            return new McpClient(new StdioMcpTransport(
                command: $command,
                cwd: isset($definition->extra['cwd']) ? (string) $definition->extra['cwd'] : null,
                environment: is_array($definition->extra['env'] ?? null) ? $definition->extra['env'] : [],
            ));
        }

        throw new InvalidArgumentException("Unsupported MCP transport [{$definition->transport}].");
    }
}
