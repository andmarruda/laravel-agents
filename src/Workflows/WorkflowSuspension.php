<?php

namespace Andmarruda\LaravelAgents\Workflows;

class WorkflowSuspension
{
    /**
     * Create an internal suspension wrapper for a pending workflow snapshot.
     *
     * @param WorkflowSnapshot $snapshot Snapshot that can be stored and resumed later.
     * @return void
     */
    public function __construct(
        public readonly WorkflowSnapshot $snapshot,
    ) {
    }
}
