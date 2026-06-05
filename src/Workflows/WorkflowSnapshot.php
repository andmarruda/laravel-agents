<?php

namespace Andmarruda\LaravelAgents\Workflows;

class WorkflowSnapshot
{
    /**
     * Create a workflow execution snapshot that can be stored and resumed later.
     *
     * @param string $id Snapshot identifier.
     * @param string $workflow Workflow name or class represented by the snapshot.
     * @param string $status Snapshot status, such as awaiting_approval or suspended.
     * @param int $nodeIndex Index of the next workflow node to execute.
     * @param mixed $data Current workflow data at the suspension point.
     * @param array<string, mixed> $context Serialized workflow context values.
     * @param array<int, array<string, mixed>> $steps Step history captured before suspension.
     * @param array<string, mixed>|null $approval Pending approval metadata, when applicable.
     * @return void
     */
    public function __construct(
        public readonly string $id,
        public readonly string $workflow,
        public readonly string $status,
        public readonly int $nodeIndex,
        public readonly mixed $data,
        public readonly array $context = [],
        public readonly array $steps = [],
        public readonly ?array $approval = null,
    ) {
    }

    /**
     * Convert the snapshot to an array that can be JSON encoded or persisted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow' => $this->workflow,
            'status' => $this->status,
            'node_index' => $this->nodeIndex,
            'data' => $this->data,
            'context' => $this->context,
            'steps' => $this->steps,
            'approval' => $this->approval,
        ];
    }

    /**
     * Rebuild a snapshot from a stored array payload.
     *
     * @param array<string, mixed> $payload Stored snapshot payload.
     * @return static
     */
    public static function fromArray(array $payload): static
    {
        return new static(
            id: (string) $payload['id'],
            workflow: (string) $payload['workflow'],
            status: (string) $payload['status'],
            nodeIndex: (int) $payload['node_index'],
            data: $payload['data'] ?? null,
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            steps: is_array($payload['steps'] ?? null) ? $payload['steps'] : [],
            approval: is_array($payload['approval'] ?? null) ? $payload['approval'] : null,
        );
    }
}
