<?php

namespace Andmarruda\LaravelAgents\MCP\Data;

class McpToolResult
{
    /**
     * @param array<int, array<string, mixed>>|null $contentBlocks
     * @param array<string, mixed>|null $structuredContent
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly mixed $content,
        public readonly bool $isError = false,
        public readonly ?array $contentBlocks = null,
        public readonly ?array $structuredContent = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toMcpArray(): array
    {
        $structuredContent = $this->structuredContent;
        if ($structuredContent === null && is_array($this->content) && $this->isAssociativeArray($this->content)) {
            $structuredContent = $this->content;
        }

        $payload = [
            'content' => $this->contentBlocks ?? $this->contentBlocksFrom($this->content),
        ];

        if ($this->isError) {
            $payload['isError'] = true;
        }

        if ($structuredContent !== null) {
            $payload['structuredContent'] = $structuredContent;
        }

        if ($this->meta !== null) {
            $payload['_meta'] = $this->meta;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function contentBlocksFrom(mixed $content): array
    {
        if ($this->looksLikeContentBlocks($content)) {
            return $content;
        }

        return [[
            'type' => 'text',
            'text' => is_string($content)
                ? $content
                : (json_encode($content, JSON_UNESCAPED_SLASHES) ?: ''),
        ]];
    }

    protected function looksLikeContentBlocks(mixed $content): bool
    {
        if (! is_array($content) || $content === []) {
            return false;
        }

        foreach ($content as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
