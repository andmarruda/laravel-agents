<?php

namespace Andmarruda\LaravelAgents\Guardrails\Data;

class Violation
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
            'metadata' => $this->metadata,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }
}
