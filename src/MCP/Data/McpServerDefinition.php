<?php

namespace Andmarruda\LaravelAgents\MCP\Data;

class McpServerDefinition
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly ?string $url = null,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            transport: (string) ($config['transport'] ?? 'http'),
            url: isset($config['url']) ? (string) $config['url'] : null,
            headers: is_array($config['headers'] ?? null) ? $config['headers'] : [],
            extra: $config,
        );
    }
}
