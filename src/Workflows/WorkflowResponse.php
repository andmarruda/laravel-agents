<?php

namespace Andmarruda\LaravelAgents\Workflows;

class WorkflowResponse
{
    /**
     * Create an immutable workflow response.
     *
     * @param mixed $data Final workflow output.
     * @param array<int, array<string, mixed>> $steps
     *        Ordered workflow execution history.
     * @param array<string, mixed> $meta
     *        Metadata such as workflow name, step count, and final context.
     * @param string $status Workflow execution status.
     * @param WorkflowSnapshot|null $snapshot Snapshot returned when the workflow suspended.
     * @return void
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $steps = [],
        public readonly array $meta = [],
        public readonly string $status = 'completed',
        public readonly ?WorkflowSnapshot $snapshot = null,
    ) {
    }
}
