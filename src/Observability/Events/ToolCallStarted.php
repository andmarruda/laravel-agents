<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class ToolCallStarted
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $input,
    ) {
    }
}
