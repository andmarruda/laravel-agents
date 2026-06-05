<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Workflows\Step;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;

class AddTaxStep implements Step
{
    /**
     * Add a fixed tax amount to the input total for workflow tests.
     *
     * @param array<string, mixed> $input
     *        Input payload containing a numeric total.
     * @param WorkflowContext $context Shared workflow context.
     * @return array<string, mixed>
     *         Input payload with the updated total.
     */
    public function handle(mixed $input, WorkflowContext $context): array
    {
        return [
            ...$input,
            'total' => $input['total'] + 8,
        ];
    }
}
