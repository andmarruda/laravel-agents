<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Throwable;

class WorkflowFailed
{
    public function __construct(
        public readonly string $workflow,
        public readonly Throwable $exception,
    ) {
    }
}
