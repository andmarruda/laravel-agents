<?php

namespace Andmarruda\LaravelAgents\Data;

class AgentResponse
{
    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $content,
        public readonly array $steps = [],
        public readonly array $meta = [],
    ) {
    }
}
