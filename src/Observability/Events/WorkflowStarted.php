<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class WorkflowStarted
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $workflow,
        public readonly mixed $input,
        public readonly array $context = [],
    ) {
    }
}
