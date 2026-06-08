<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Throwable;

class WorkflowStepFailed
{
    public function __construct(
        public readonly string $workflow,
        public readonly string $step,
        public readonly string $type,
        public readonly Throwable $exception,
    ) {
    }
}
