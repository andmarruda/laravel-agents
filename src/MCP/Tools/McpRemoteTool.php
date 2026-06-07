<?php

namespace Andmarruda\LaravelAgents\MCP\Tools;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\MCP\Client\McpClient;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;

class McpRemoteTool implements Tool
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        protected string $server,
        protected string $remoteName,
        protected string $description,
        protected array $schema,
        protected McpClient $client,
        protected ?ToolSchemaConverter $schemaConverter = null,
    ) {
        $this->schemaConverter ??= new ToolSchemaConverter();
    }

    public function name(): string
    {
        return $this->server.'.'.$this->remoteName;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): array
    {
        return $this->schemaConverter->toMcpInputSchema($this->schema);
    }

    public function handle(array $input): mixed
    {
        return $this->client->callTool($this->remoteName, $input);
    }
}
