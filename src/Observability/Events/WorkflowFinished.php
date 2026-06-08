<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Andmarruda\LaravelAgents\Workflows\WorkflowResponse;

class WorkflowFinished
{
    public function __construct(
        public readonly string $workflow,
        public readonly WorkflowResponse $response,
    ) {
    }
}
