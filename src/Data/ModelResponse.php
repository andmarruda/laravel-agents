<?php

namespace Andmarruda\LaravelAgents\Data;

class ModelResponse
{
    /**
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly string $provider,
        public readonly array $usage = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
