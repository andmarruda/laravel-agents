<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class WorkflowStepFinished
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $step,
        public readonly string $type,
        public readonly mixed $output = null,
    ) {
    }
}
