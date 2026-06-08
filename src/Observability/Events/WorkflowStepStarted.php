<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class WorkflowStepStarted
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $step,
        public readonly string $type,
    ) {
    }
}
