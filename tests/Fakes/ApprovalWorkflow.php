<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Workflows\Workflow;

class ApprovalWorkflow extends Workflow
{
    /**
     * Create a class-based workflow used by queue and approval tests.
     *
     * @param string|null $name Optional workflow name.
     * @return void
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name ?? 'approval-workflow');

        $this
            ->then('prepare', fn (array $input): array => [...$input, 'prepared' => true])
            ->approval('manager_approval', 'Approve this workflow?', ['role' => 'manager'])
            ->then('finish', fn (array $input): array => [...$input, 'finished' => true]);
    }
}
