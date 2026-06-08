<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class AgentRunStarted
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $agent,
        public readonly string $input,
        public readonly array $context = [],
    ) {
    }
}
