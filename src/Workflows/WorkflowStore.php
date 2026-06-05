<?php

namespace Andmarruda\LaravelAgents\Workflows;

interface WorkflowStore
{
    /**
     * Persist a workflow snapshot.
     *
     * @param WorkflowSnapshot $snapshot Snapshot to store.
     * @return void
     */
    public function put(WorkflowSnapshot $snapshot): void;

    /**
     * Retrieve a workflow snapshot by identifier.
     *
     * @param string $id Snapshot identifier.
     * @return WorkflowSnapshot|null
     */
    public function get(string $id): ?WorkflowSnapshot;
}
