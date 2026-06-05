<?php

namespace Andmarruda\LaravelAgents\Workflows;

class InMemoryWorkflowStore implements WorkflowStore
{
    /**
     * @var array<string, WorkflowSnapshot>
     */
    protected array $snapshots = [];

    /**
     * Persist a workflow snapshot in memory.
     *
     * @param WorkflowSnapshot $snapshot Snapshot to store.
     * @return void
     */
    public function put(WorkflowSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->id] = $snapshot;
    }

    /**
     * Retrieve an in-memory workflow snapshot by identifier.
     *
     * @param string $id Snapshot identifier.
     * @return WorkflowSnapshot|null
     */
    public function get(string $id): ?WorkflowSnapshot
    {
        return $this->snapshots[$id] ?? null;
    }
}
