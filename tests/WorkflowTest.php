<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\LaravelAgentsManager;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Tests\Fakes\AddTaxStep;
use Andmarruda\LaravelAgents\Tests\Fakes\ApprovalWorkflow;
use Andmarruda\LaravelAgents\Workflows\InMemoryWorkflowStore;
use Andmarruda\LaravelAgents\Workflows\Workflow;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;
use Andmarruda\LaravelAgents\Workflows\WorkflowQueueJob;
use Andmarruda\LaravelAgents\Workflows\WorkflowSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkflowTest extends TestCase
{
    /**
     * Verify that linear workflow steps run in order and expose execution metadata.
     *
     * @return void
     */
    public function test_workflow_runs_steps_in_order_and_tracks_history(): void
    {
        $response = Workflow::make('invoice-review')
            ->then('normalize', function (array $invoice, WorkflowContext $context): array {
                $context->put('currency', 'USD');

                return [
                    ...$invoice,
                    'total' => 100,
                ];
            })
            ->then(AddTaxStep::class)
            ->run(['id' => 10]);

        $this->assertSame(['id' => 10, 'total' => 108], $response->data);
        $this->assertSame('invoice-review', $response->meta['workflow']);
        $this->assertSame(2, $response->meta['steps']);
        $this->assertSame('USD', $response->meta['context']['currency']);
        $this->assertSame('normalize', $response->steps[0]['name']);
        $this->assertSame('AddTaxStep', $response->steps[1]['name']);
    }

    /**
     * Verify that a branch node executes the path selected by its decider.
     *
     * @return void
     */
    public function test_workflow_branches_by_named_decision(): void
    {
        $response = Workflow::make()
            ->branch(
                fn (array $order): string => $order['total'] >= 500 ? 'approval' : 'auto',
                [
                    'approval' => fn (array $order): array => [...$order, 'status' => 'waiting_approval'],
                    'auto' => fn (array $order): array => [...$order, 'status' => 'approved'],
                ],
                'approval_gate',
            )
            ->run(['total' => 750]);

        $this->assertSame('waiting_approval', $response->data['status']);
        $this->assertSame('branch', $response->steps[0]['type']);
        $this->assertSame('approval', $response->steps[0]['selected']);
    }

    /**
     * Verify that foreach processing preserves item keys in the final output.
     *
     * @return void
     */
    public function test_workflow_runs_foreach_and_returns_keyed_results(): void
    {
        $response = Workflow::make()
            ->forEach(
                fn (array $payload): array => $payload['items'],
                fn (int $value): int => $value * 2,
                'double_items',
            )
            ->run(['items' => ['a' => 2, 'b' => 4]]);

        $this->assertSame(['a' => 4, 'b' => 8], $response->data);
        $this->assertSame('double_items', $response->steps[0]['foreach']);
        $this->assertSame('foreach', $response->steps[2]['type']);
    }

    /**
     * Verify that loopUntil repeats until the completion condition returns true.
     *
     * @return void
     */
    public function test_workflow_loops_until_condition_is_true(): void
    {
        $response = Workflow::make()
            ->loopUntil(
                fn (int $count): int => $count + 1,
                fn (int $count): bool => $count >= 3,
                maxIterations: 5,
                name: 'wait_for_ready_state',
            )
            ->run(0);

        $this->assertSame(3, $response->data);
        $this->assertSame('loop', $response->steps[3]['type']);
        $this->assertTrue($response->steps[3]['completed']);
        $this->assertSame(3, $response->steps[3]['iterations']);
    }

    /**
     * Verify that parallel fan-out returns results in declaration order.
     *
     * @return void
     */
    public function test_workflow_parallel_returns_outputs_in_declared_order(): void
    {
        $response = Workflow::make()
            ->parallel([
                'summary' => fn (array $post): string => strtoupper($post['title']),
                'slug' => fn (array $post): string => strtolower(str_replace(' ', '-', $post['title'])),
            ], 'prepare_post')
            ->run(['title' => 'Hello Workflows']);

        $this->assertSame([
            'summary' => 'HELLO WORKFLOWS',
            'slug' => 'hello-workflows',
        ], $response->data);
        $this->assertSame(['summary', 'slug'], array_keys($response->data));
        $this->assertSame('prepare_post', $response->steps[2]['name']);
    }

    /**
     * Verify that context values can be read, written, merged, and returned.
     *
     * @return void
     */
    public function test_workflow_context_reads_writes_merges_and_exports_values(): void
    {
        $context = new WorkflowContext(['tenant_id' => 10]);

        $context
            ->put('locale', 'en')
            ->merge(['tenant_id' => 20, 'timezone' => 'UTC']);

        $this->assertSame(20, $context->get('tenant_id'));
        $this->assertSame('en', $context->get('locale'));
        $this->assertSame('UTC', $context->get('timezone'));
        $this->assertSame('fallback', $context->get('missing', 'fallback'));
        $this->assertSame([
            'tenant_id' => 20,
            'locale' => 'en',
            'timezone' => 'UTC',
        ], $context->all());
    }

    /**
     * Verify that a missing branch path fails loudly.
     *
     * @return void
     */
    public function test_workflow_throws_when_branch_selects_missing_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selected missing path');

        Workflow::make()
            ->branch(
                fn (): string => 'manual',
                ['auto' => fn (array $input): array => $input],
                'approval_gate',
            )
            ->run([]);
    }

    /**
     * Verify that loop definitions require at least one iteration.
     *
     * @return void
     */
    public function test_workflow_rejects_invalid_loop_iteration_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1');

        Workflow::make()->loopUntil(
            fn (int $count): int => $count + 1,
            fn (): bool => true,
            maxIterations: 0,
        );
    }

    /**
     * Verify that loopUntil records incomplete loops when the condition is never met.
     *
     * @return void
     */
    public function test_workflow_records_incomplete_loop_after_max_iterations(): void
    {
        $response = Workflow::make()
            ->loopUntil(
                fn (int $count): int => $count + 1,
                fn (): bool => false,
                maxIterations: 2,
                name: 'retry_limit',
            )
            ->run(0);

        $this->assertSame(2, $response->data);
        $this->assertSame('loop', $response->steps[2]['type']);
        $this->assertFalse($response->steps[2]['completed']);
        $this->assertSame(2, $response->steps[2]['iterations']);
    }

    /**
     * Verify that foreach requires an iterable item source.
     *
     * @return void
     */
    public function test_workflow_throws_when_foreach_items_are_not_iterable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must resolve to an iterable');

        Workflow::make()
            ->forEach(
                fn (): string => 'not iterable',
                fn (mixed $item): mixed => $item,
                'bad_items',
            )
            ->run([]);
    }

    /**
     * Verify that the manager can create or return workflow instances.
     *
     * @return void
     */
    public function test_manager_resolves_workflow_builder_and_instances(): void
    {
        $models = new ModelRouter([]);
        $images = new ImageRouter([]);
        $manager = new LaravelAgentsManager(
            $models,
            $images,
            new AgentKernel($models, $images),
        );

        $created = $manager->workflow();
        $existing = Workflow::make('existing');

        $this->assertInstanceOf(Workflow::class, $created);
        $this->assertSame($existing, $manager->workflow($existing));
    }

    /**
     * Verify that an approval step suspends the workflow and stores a resumable snapshot.
     *
     * @return void
     */
    public function test_workflow_suspends_for_human_approval_and_stores_snapshot(): void
    {
        $store = new InMemoryWorkflowStore();
        $response = (new ApprovalWorkflow())->run(['id' => 1], store: $store);

        $this->assertSame('awaiting_approval', $response->status);
        $this->assertInstanceOf(WorkflowSnapshot::class, $response->snapshot);
        $this->assertSame('manager_approval', $response->snapshot->approval['name']);
        $this->assertSame('Approve this workflow?', $response->snapshot->approval['prompt']);
        $this->assertSame($response->snapshot, $store->get($response->snapshot->id));
    }

    /**
     * Verify that a suspended workflow can resume from a stored snapshot.
     *
     * @return void
     */
    public function test_workflow_resumes_from_snapshot_with_approval_value(): void
    {
        $store = new InMemoryWorkflowStore();
        $workflow = new ApprovalWorkflow();
        $suspended = $workflow->run(['id' => 1], store: $store);

        $resumed = (new ApprovalWorkflow())->resume($suspended->snapshot->id, ['approved_by' => 99], $store);

        $this->assertSame('completed', $resumed->status);
        $this->assertSame(['id' => 1, 'prepared' => true, 'finished' => true], $resumed->data);
        $this->assertSame('approved', $resumed->steps[2]['status']);
        $this->assertSame(['approved_by' => 99], $resumed->steps[2]['approval']);
        $this->assertSame($suspended->snapshot->id, $resumed->meta['resumed_from']);
    }

    /**
     * Verify that snapshots can be converted to arrays and rebuilt.
     *
     * @return void
     */
    public function test_workflow_snapshot_round_trips_through_array_payload(): void
    {
        $snapshot = new WorkflowSnapshot(
            id: 'snapshot-1',
            workflow: 'billing',
            status: 'awaiting_approval',
            nodeIndex: 2,
            data: ['total' => 100],
            context: ['tenant' => 10],
            steps: [['step' => 1, 'type' => 'step']],
            approval: ['name' => 'approve'],
        );

        $rebuilt = WorkflowSnapshot::fromArray($snapshot->toArray());

        $this->assertSame($snapshot->toArray(), $rebuilt->toArray());
    }

    /**
     * Verify that class-based workflows can be wrapped in queue jobs.
     *
     * @return void
     */
    public function test_workflow_dispatch_returns_queue_job_that_can_handle_workflow(): void
    {
        $store = new InMemoryWorkflowStore();
        $job = (new ApprovalWorkflow())->dispatch(['id' => 5], ['source' => 'test']);

        $this->assertInstanceOf(WorkflowQueueJob::class, $job);
        $this->assertSame(ApprovalWorkflow::class, $job->workflowClass);

        $response = $job->handle($store);

        $this->assertSame('awaiting_approval', $response->status);
        $this->assertSame(5, $response->data['id']);
        $this->assertNotNull($store->get($response->snapshot->id));
    }

    /**
     * Verify that input and output schemas validate workflow payloads.
     *
     * @return void
     */
    public function test_workflow_validates_input_and_output_schemas(): void
    {
        $response = Workflow::make()
            ->inputSchema(['name' => 'required|string'])
            ->outputSchema(['message' => 'required|string'])
            ->then('greet', fn (array $input): array => ['message' => 'Hello '.$input['name']])
            ->run(['name' => 'Ana']);

        $this->assertSame(['message' => 'Hello Ana'], $response->data);
    }

    /**
     * Verify that input schema validation rejects invalid payloads.
     *
     * @return void
     */
    public function test_workflow_input_schema_rejects_invalid_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field [name] must be string');

        Workflow::make()
            ->inputSchema(['name' => 'required|string'])
            ->then('noop', fn (array $input): array => $input)
            ->run(['name' => 123]);
    }

    /**
     * Verify that output schema validation rejects invalid final data.
     *
     * @return void
     */
    public function test_workflow_output_schema_rejects_invalid_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field [message] is required');

        Workflow::make()
            ->outputSchema(['message' => 'required|string'])
            ->then('bad_output', fn (): array => ['missing' => true])
            ->run([]);
    }
}
