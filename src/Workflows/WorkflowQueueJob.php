<?php

namespace Andmarruda\LaravelAgents\Workflows;

use Illuminate\Contracts\Queue\ShouldQueue;

class WorkflowQueueJob implements ShouldQueue
{
    /**
     * Create a queue job that can execute a class-based workflow.
     *
     * @param class-string<Workflow> $workflowClass Workflow class to instantiate when the job handles.
     * @param mixed $input Initial workflow input.
     * @param array<string, mixed> $context Initial workflow context.
     * @param string|null $snapshotId Optional snapshot id used when storing suspended output.
     * @return void
     */
    public function __construct(
        public readonly string $workflowClass,
        public readonly mixed $input = null,
        public readonly array $context = [],
        public readonly ?string $snapshotId = null,
    ) {
    }

    /**
     * Execute the queued workflow and persist a suspension snapshot when needed.
     *
     * @param WorkflowStore|null $store Optional store used for suspended workflow snapshots.
     * @return WorkflowResponse
     */
    public function handle(?WorkflowStore $store = null): WorkflowResponse
    {
        $workflow = new $this->workflowClass();
        $response = $workflow->run($this->input, $this->context, $store);

        if ($store && $response->snapshot) {
            $store->put($response->snapshot);
        }

        return $response;
    }
}
