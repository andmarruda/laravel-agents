<?php

namespace Andmarruda\LaravelAgents\MCP\Data;

class McpToolDefinition
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $outputSchema
     * @param array<string, mixed>|null $annotations
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $schema,
        public readonly ?string $title = null,
        public readonly ?array $outputSchema = null,
        public readonly ?array $annotations = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toMcpArray(): array
    {
        $definition = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->schema,
        ];

        if ($this->title !== null) {
            $definition['title'] = $this->title;
        }

        if ($this->outputSchema !== null) {
            $definition['outputSchema'] = $this->outputSchema;
        }

        if ($this->annotations !== null) {
            $definition['annotations'] = $this->annotations;
        }

        if ($this->meta !== null) {
            $definition['_meta'] = $this->meta;
        }

        return $definition;
    }
}
