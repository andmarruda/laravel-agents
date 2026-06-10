<?php

namespace Andmarruda\LaravelAgents\Guardrails\Data;

class GuardrailContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $metadata = [],
        public readonly ?string $agent = null,
        public readonly ?string $tool = null,
        public readonly string $phase = 'before',
        public readonly int $attempt = 1,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function with(string $key, mixed $value): static
    {
        return new static(
            $this->operation,
            [...$this->metadata, $key => $value],
            $this->agent,
            $this->tool,
            $this->phase,
            $this->attempt,
        );
    }
}
