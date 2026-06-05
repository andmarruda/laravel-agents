<?php

namespace Andmarruda\LaravelAgents\Workflows;

interface Step
{
    /**
     * Execute a reusable workflow step.
     *
     * @param mixed $input Current step input.
     * @param WorkflowContext $context Shared workflow context.
     * @return mixed Step output passed to the next workflow node.
     */
    public function handle(mixed $input, WorkflowContext $context): mixed;
}
