<?php

namespace Andmarruda\LaravelAgents\MCP\Server;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\MCP\Client\McpClient;
use Andmarruda\LaravelAgents\MCP\Client\McpServerConnection;
use Andmarruda\LaravelAgents\MCP\Data\McpServerDefinition;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use Andmarruda\LaravelAgents\MCP\Tools\ControllerTool;
use Andmarruda\LaravelAgents\MCP\Tools\McpRemoteTool;
use Andmarruda\LaravelAgents\MCP\Tools\RouteTool;
use InvalidArgumentException;

class McpToolRegistry
{
    /**
     * @var array<string, Tool>
     */
    protected array $localTools = [];

    /**
     * @var array<string, array<string, Tool>>
     */
    protected array $remoteTools = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config = [],
        protected ?McpServerConnection $connection = null,
        protected ?ToolSchemaConverter $schemaConverter = null,
    ) {
        $this->connection ??= new McpServerConnection();
        $this->schemaConverter ??= new ToolSchemaConverter();
    }

    /**
     * @return array<string, Tool>
     */
    public function localTools(): array
    {
        if ($this->localTools === []) {
            $this->loadConfiguredLocalTools();
        }

        return $this->localTools;
    }

    public function registerLocalTool(Tool|string $tool): static
    {
        $instance = is_string($tool) ? $this->resolve($tool) : $tool;

        $this->localTools[$instance->name()] = $instance;

        return $this;
    }

    public function getLocalTool(string $name): Tool
    {
        $tools = $this->localTools();

        return $tools[$name] ?? throw new InvalidArgumentException("MCP tool [{$name}] is not registered.");
    }

    /**
     * @return array<string, Tool>
     */
    public function toolsForAgent(string $agentClass, array $servers = [], array $allowedTools = []): array
    {
        $policy = $this->config['agents'][$agentClass] ?? [];
        $configuredServers = is_array($policy['servers'] ?? null) ? $policy['servers'] : null;
        $configuredTools = is_array($policy['tools'] ?? null) ? $policy['tools'] : null;

        $serverNames = $servers;
        if ($configuredServers !== null) {
            $serverNames = $serverNames === []
                ? $configuredServers
                : array_values(array_intersect($serverNames, $configuredServers));
        }

        $toolNames = $allowedTools;
        if ($configuredTools !== null) {
            $toolNames = $toolNames === []
                ? $configuredTools
                : array_values(array_intersect($toolNames, $configuredTools));
        }

        $tools = [];
        foreach ($serverNames as $serverName) {
            foreach ($this->remoteToolsForServer((string) $serverName) as $name => $tool) {
                if ($toolNames !== [] && ! in_array($name, $toolNames, true)) {
                    continue;
                }

                $tools[$name] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return array<string, Tool>
     */
    public function remoteToolsForServer(string $serverName): array
    {
        if (isset($this->remoteTools[$serverName])) {
            return $this->remoteTools[$serverName];
        }

        $serverConfig = $this->config['clients']['servers'][$serverName] ?? null;

        if (! is_array($serverConfig)) {
            throw new InvalidArgumentException("MCP server [{$serverName}] is not configured.");
        }

        $client = $this->connection->connect(McpServerDefinition::fromConfig($serverName, $serverConfig));

        return $this->remoteTools[$serverName] = $this->toolsFromRemoteClient($serverName, $client);
    }

    /**
     * @return array<string, Tool>
     */
    public function toolsFromRemoteClient(string $serverName, McpClient $client): array
    {
        $tools = [];

        foreach ($client->listTools() as $definition) {
            $remoteName = (string) ($definition['name'] ?? '');

            if ($remoteName === '') {
                continue;
            }

            $tool = new McpRemoteTool(
                server: $serverName,
                remoteName: $remoteName,
                description: (string) ($definition['description'] ?? ''),
                schema: is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : ['type' => 'object'],
                client: $client,
                schemaConverter: $this->schemaConverter,
            );

            $tools[$tool->name()] = $tool;
        }

        return $tools;
    }

    protected function loadConfiguredLocalTools(): void
    {
        foreach ($this->config['server']['tools'] ?? [] as $tool) {
            if (is_string($tool) || $tool instanceof Tool) {
                $this->registerLocalTool($tool);
            }
        }

        foreach ($this->config['server']['controllers'] ?? [] as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $uses = $definition['uses'] ?? null;
            if (! is_array($uses) || count($uses) !== 2) {
                throw new InvalidArgumentException("MCP controller tool [{$name}] must define a [uses] pair.");
            }

            $this->registerLocalTool(new ControllerTool(
                name: (string) $name,
                description: (string) ($definition['description'] ?? ''),
                controller: $uses[0],
                method: (string) $uses[1],
                schema: is_array($definition['schema'] ?? null) ? $definition['schema'] : ['type' => 'object'],
                schemaConverter: $this->schemaConverter,
            ));
        }

        foreach ($this->config['server']['routes'] ?? [] as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $this->registerLocalTool(new RouteTool(
                name: (string) $name,
                description: (string) ($definition['description'] ?? ''),
                route: (string) ($definition['route'] ?? $name),
                method: (string) ($definition['method'] ?? 'POST'),
                schema: is_array($definition['schema'] ?? null) ? $definition['schema'] : ['type' => 'object'],
                map: is_array($definition['map'] ?? null) ? $definition['map'] : [],
                schemaConverter: $this->schemaConverter,
            ));
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function resolve(string $class): object
    {
        if (function_exists('app')) {
            return app($class);
        }

        return new $class();
    }
}
