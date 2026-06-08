<?php

namespace Andmarruda\LaravelAgents\Workflows;

use Closure;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowFailed;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowFinished;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowStarted;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowStepFailed;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowStepFinished;
use Andmarruda\LaravelAgents\Observability\Events\WorkflowStepStarted;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Workflow
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $nodes = [];

    /**
     * @var array<string, string>|null
     */
    protected ?array $inputSchema = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $outputSchema = null;

    protected ?TraceManager $traceManager = null;

    /**
     * Create a workflow instance with an optional display name.
     *
     * @param string|null $name Optional workflow name used in response metadata.
     * @return void
     */
    public function __construct(
        protected ?string $name = null,
    ) {
    }

    /**
     * Create a new workflow instance.
     *
     * @param string|null $name Optional workflow name used in response metadata.
     * @return static
     */
    public static function make(?string $name = null): static
    {
        return new static($name);
    }

    public function setTraceManager(?TraceManager $traceManager): static
    {
        $this->traceManager = $traceManager;

        return $this;
    }

    /**
     * Set the workflow name used in response metadata.
     *
     * @param string $name Human-readable workflow name.
     * @return static
     */
    public function named(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Add a deterministic step to the workflow pipeline.
     *
     * @param string|callable $nameOrStep Step name, step class name, or callable handler.
     * @param string|callable|null $step Optional step class name or callable handler when the first argument is a name.
     * @return static
     */
    public function then(string|callable $nameOrStep, string|callable|null $step = null): static
    {
        [$name, $handler] = $this->normalizeNamedHandler($nameOrStep, $step);

        $this->nodes[] = [
            'type' => 'step',
            'name' => $name,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Add a branch node that chooses one named path at runtime.
     *
     * @param callable $decider Receives the current input and context, then returns the selected branch key.
     * @param array<string|int, Workflow|string|callable|array<int, string|callable>> $branches
     *        Branch definitions keyed by the values returned from the decider.
     * @param string|null $name Optional branch name used in step history.
     * @return static
     */
    public function branch(callable $decider, array $branches, ?string $name = null): static
    {
        $normalized = [];

        foreach ($branches as $key => $branch) {
            $normalized[$key] = $this->normalizePipeline($branch, is_string($key) ? $key : null);
        }

        $this->nodes[] = [
            'type' => 'branch',
            'name' => $name ?? 'branch_'.(count($this->nodes) + 1),
            'decider' => $decider,
            'branches' => $normalized,
        ];

        return $this;
    }

    /**
     * Add a deterministic fan-out/fan-in node.
     *
     * @param array<string|int, Workflow|string|callable> $steps
     *        Steps or workflows to run against the same input, keyed by result name when desired.
     * @param string|null $name Optional parallel node name used in step history.
     * @return static
     */
    public function parallel(array $steps, ?string $name = null): static
    {
        $normalized = [];

        foreach ($steps as $key => $step) {
            $normalized[$key] = $this->normalizePipeline($step, is_string($key) ? $key : null);
        }

        $this->nodes[] = [
            'type' => 'parallel',
            'name' => $name ?? 'parallel_'.(count($this->nodes) + 1),
            'steps' => $normalized,
        ];

        return $this;
    }

    /**
     * Add a bounded loop that repeats a step until a condition returns true.
     *
     * @param string|callable|Workflow $step Step, callable, or workflow to execute on each iteration.
     * @param callable $condition Receives the current output, context, and iteration number, then returns true when complete.
     * @param int $maxIterations Maximum number of iterations before the loop stops.
     * @param string|null $name Optional loop name used in step history.
     * @return static
     */
    public function loopUntil(
        string|callable|Workflow $step,
        callable $condition,
        int $maxIterations = 10,
        ?string $name = null,
    ): static {
        if ($maxIterations < 1) {
            throw new InvalidArgumentException('Workflow loop max iterations must be at least 1.');
        }

        $this->nodes[] = [
            'type' => 'loop',
            'name' => $name ?? 'loop_'.(count($this->nodes) + 1),
            'step' => $this->normalizePipeline($step),
            'condition' => $condition,
            'max_iterations' => $maxIterations,
        ];

        return $this;
    }

    /**
     * Add a foreach node that processes an iterable set of items.
     *
     * @param callable $items Receives the current input and context, then returns the iterable items to process.
     * @param string|callable|Workflow $step Step, callable, or workflow to run for each item.
     * @param string|null $name Optional foreach node name used in step history.
     * @return static
     */
    public function forEach(callable $items, string|callable|Workflow $step, ?string $name = null): static
    {
        $this->nodes[] = [
            'type' => 'foreach',
            'name' => $name ?? 'foreach_'.(count($this->nodes) + 1),
            'items' => $items,
            'step' => $this->normalizePipeline($step),
        ];

        return $this;
    }

    /**
     * Add a human approval point that suspends the workflow until it is resumed.
     *
     * @param string $name Approval step name.
     * @param string $prompt Human-readable approval prompt.
     * @param array<string, mixed> $metadata Extra metadata stored in the approval snapshot.
     * @return static
     */
    public function approval(string $name, string $prompt, array $metadata = []): static
    {
        $this->nodes[] = [
            'type' => 'approval',
            'name' => $name,
            'prompt' => $prompt,
            'metadata' => $metadata,
        ];

        return $this;
    }

    /**
     * Define simple validation rules for the initial workflow input.
     *
     * @param array<string, string> $schema Field rules such as required, string, integer, numeric, array, or boolean.
     * @return static
     */
    public function inputSchema(array $schema): static
    {
        $this->inputSchema = $schema;

        return $this;
    }

    /**
     * Define simple validation rules for the final workflow output.
     *
     * @param array<string, string> $schema Field rules such as required, string, integer, numeric, array, or boolean.
     * @return static
     */
    public function outputSchema(array $schema): static
    {
        $this->outputSchema = $schema;

        return $this;
    }

    /**
     * Execute the workflow synchronously and return the final output with step history.
     *
     * @param mixed $input Initial workflow input.
     * @param array<string, mixed> $context
     *        Initial context values shared by workflow steps.
     * @param WorkflowStore|null $store Optional snapshot store used when the workflow suspends.
     * @return WorkflowResponse
     */
    public function run(mixed $input = null, array $context = [], ?WorkflowStore $store = null): WorkflowResponse
    {
        $traceManager = $this->traceManager();
        $trace = $traceManager?->startTrace('workflow.run', [
            'workflow.name' => $this->workflowName(),
            'workflow.class' => static::class,
        ]);
        $traceManager?->dispatch(new WorkflowStarted($this->workflowName(), $input, $context));

        try {
            $this->validateSchema($input, $this->inputSchema, 'input');

            $workflowContext = new WorkflowContext($context);
            $steps = [];
            $output = $this->runNodes($this->nodes, $input, $workflowContext, $steps);

            if ($output instanceof WorkflowSuspension) {
                if ($store) {
                    $store->put($output->snapshot);
                }

                $response = new WorkflowResponse($output->snapshot->data, $output->snapshot->steps, [
                    'workflow' => $this->workflowName(),
                    'steps' => count($output->snapshot->steps),
                    'context' => $output->snapshot->context,
                    'snapshot_id' => $output->snapshot->id,
                    'approval' => $output->snapshot->approval,
                    'trace_id' => $trace?->id,
                ], $output->snapshot->status, $output->snapshot);
                $traceManager?->dispatch(new WorkflowFinished($this->workflowName(), $response));
                $traceManager?->finishTrace($trace, ['workflow.status' => $output->snapshot->status]);

                return $response;
            }

            $this->validateSchema($output, $this->outputSchema, 'output');

            $response = new WorkflowResponse($output, $steps, [
                'workflow' => $this->workflowName(),
                'steps' => count($steps),
                'context' => $workflowContext->all(),
                'trace_id' => $trace?->id,
            ]);
            $traceManager?->dispatch(new WorkflowFinished($this->workflowName(), $response));
            $traceManager?->finishTrace($trace, ['workflow.steps' => count($steps)]);

            return $response;
        } catch (Throwable $throwable) {
            $traceManager?->dispatch(new WorkflowFailed($this->workflowName(), $throwable));
            $traceManager?->failTrace($trace, $throwable);

            throw $throwable;
        }
    }

    /**
     * Resume a suspended workflow snapshot, optionally providing a human approval value.
     *
     * @param WorkflowSnapshot|string $snapshot Snapshot instance or identifier stored in the provided store.
     * @param mixed $approval Approval value injected for the pending approval node.
     * @param WorkflowStore|null $store Store used when loading a snapshot id or persisting a new suspension.
     * @return WorkflowResponse
     */
    public function resume(WorkflowSnapshot|string $snapshot, mixed $approval = null, ?WorkflowStore $store = null): WorkflowResponse
    {
        $traceManager = $this->traceManager();
        $trace = $traceManager?->startTrace('workflow.resume', [
            'workflow.name' => $this->workflowName(),
            'workflow.class' => static::class,
        ]);

        try {
            if (is_string($snapshot)) {
                $snapshot = $store?->get($snapshot)
                    ?? throw new RuntimeException("Workflow snapshot [{$snapshot}] was not found.");
            }

            $traceManager?->dispatch(new WorkflowStarted($this->workflowName(), $snapshot->data, $snapshot->context));
            $context = new WorkflowContext($snapshot->context);

            if ($snapshot->approval && $approval !== null) {
                $approvals = $context->get('_approvals', []);
                $approvals[$snapshot->approval['name']] = $approval;
                $context->put('_approvals', $approvals);
            }

            $steps = $snapshot->steps;
            $output = $this->runNodes($this->nodes, $snapshot->data, $context, $steps, $snapshot->nodeIndex);

            if ($output instanceof WorkflowSuspension) {
                if ($store) {
                    $store->put($output->snapshot);
                }

                $response = new WorkflowResponse($output->snapshot->data, $output->snapshot->steps, [
                    'workflow' => $this->workflowName(),
                    'steps' => count($output->snapshot->steps),
                    'context' => $output->snapshot->context,
                    'snapshot_id' => $output->snapshot->id,
                    'approval' => $output->snapshot->approval,
                    'trace_id' => $trace?->id,
                ], $output->snapshot->status, $output->snapshot);
                $traceManager?->dispatch(new WorkflowFinished($this->workflowName(), $response));
                $traceManager?->finishTrace($trace, ['workflow.status' => $output->snapshot->status]);

                return $response;
            }

            $this->validateSchema($output, $this->outputSchema, 'output');

            $response = new WorkflowResponse($output, $steps, [
                'workflow' => $this->workflowName(),
                'steps' => count($steps),
                'context' => $context->all(),
                'resumed_from' => $snapshot->id,
                'trace_id' => $trace?->id,
            ]);
            $traceManager?->dispatch(new WorkflowFinished($this->workflowName(), $response));
            $traceManager?->finishTrace($trace, ['workflow.steps' => count($steps), 'workflow.resumed_from' => $snapshot->id]);

            return $response;
        } catch (Throwable $throwable) {
            $traceManager?->dispatch(new WorkflowFailed($this->workflowName(), $throwable));
            $traceManager?->failTrace($trace, $throwable);

            throw $throwable;
        }
    }

    /**
     * Build a queue job for class-based workflow execution.
     *
     * @param mixed $input Initial workflow input.
     * @param array<string, mixed> $context Initial workflow context.
     * @param string|null $snapshotId Optional snapshot identifier associated with the job.
     * @return WorkflowQueueJob
     */
    public function toQueuedJob(mixed $input = null, array $context = [], ?string $snapshotId = null): WorkflowQueueJob
    {
        return new WorkflowQueueJob(static::class, $input, $context, $snapshotId);
    }

    /**
     * Dispatch the workflow to Laravel's bus when available, or return the queue job.
     *
     * @param mixed $input Initial workflow input.
     * @param array<string, mixed> $context Initial workflow context.
     * @param BusDispatcher|null $dispatcher Optional dispatcher for tests or manual integration.
     * @param string|null $snapshotId Optional snapshot identifier associated with the job.
     * @return mixed Dispatcher result when dispatched, otherwise the WorkflowQueueJob instance.
     */
    public function dispatch(
        mixed $input = null,
        array $context = [],
        ?BusDispatcher $dispatcher = null,
        ?string $snapshotId = null,
    ): mixed {
        $job = $this->toQueuedJob($input, $context, $snapshotId);

        if ($dispatcher) {
            return $dispatcher->dispatch($job);
        }

        if (function_exists('app')) {
            try {
                return app(BusDispatcher::class)->dispatch($job);
            } catch (Throwable) {
                return $job;
            }
        }

        return $job;
    }

    /**
     * Execute a list of workflow nodes in order.
     *
     * @param array<int, array<string, mixed>> $nodes
     *        Normalized workflow nodes to execute.
     * @param mixed $input Input for the first node.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @param int $startIndex Node index used when resuming from a snapshot.
     * @return mixed Final output after all nodes have run or a suspension marker.
     */
    protected function runNodes(array $nodes, mixed $input, WorkflowContext $context, array &$steps, int $startIndex = 0): mixed
    {
        $current = $input;

        for ($index = $startIndex; $index < count($nodes); $index++) {
            $node = $nodes[$index];
            $current = $this->runNodeWithObservability($node, $current, $context, $steps, $index);

            if ($current instanceof WorkflowSuspension) {
                return $current;
            }
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $steps
     */
    protected function runNodeWithObservability(array $node, mixed $current, WorkflowContext $context, array &$steps, int $index): mixed
    {
        $name = (string) ($node['name'] ?? $node['type']);
        $type = (string) $node['type'];
        $traceManager = $this->traceManager();
        $span = $traceManager?->startSpan('workflow.'.$type, 'workflow', [
            'workflow.name' => $this->workflowName(),
            'workflow.node' => $name,
            'workflow.node_type' => $type,
        ]);
        $traceManager?->dispatch(new WorkflowStepStarted($this->workflowName(), $name, $type));

        try {
            $output = match ($type) {
                'step' => $this->runStep($name, $node['handler'], $current, $context, $steps),
                'branch' => $this->runBranch($node, $current, $context, $steps),
                'parallel' => $this->runParallel($node, $current, $context, $steps),
                'loop' => $this->runLoop($node, $current, $context, $steps),
                'foreach' => $this->runForeach($node, $current, $context, $steps),
                'approval' => $this->runApproval($node, $current, $context, $steps, $index),
                default => throw new RuntimeException("Unknown workflow node [{$type}]."),
            };

            $traceManager?->finishSpan($span, [
                'workflow.suspended' => $output instanceof WorkflowSuspension,
            ]);
            $traceManager?->dispatch(new WorkflowStepFinished($this->workflowName(), $name, $type, $output));

            return $output;
        } catch (Throwable $throwable) {
            $traceManager?->failSpan($span, $throwable);
            $traceManager?->dispatch(new WorkflowStepFailed($this->workflowName(), $name, $type, $throwable));

            throw $throwable;
        }
    }

    /**
     * Execute a single step node and append its input and output to history.
     *
     * @param string $name Step name stored in history.
     * @param mixed $handler Callable or Step class that handles the step.
     * @param mixed $input Current step input.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @return mixed Step output.
     */
    protected function runStep(string $name, mixed $handler, mixed $input, WorkflowContext $context, array &$steps): mixed
    {
        $output = $this->invoke($handler, $input, $context);

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'step',
            'name' => $name,
            'input' => $input,
            'output' => $output,
        ];

        return $output;
    }

    /**
     * Execute a branch node by running the path selected by the decider.
     *
     * @param array<string, mixed> $node
     *        Branch node definition.
     * @param mixed $input Current workflow input.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @return mixed Output from the selected branch.
     */
    protected function runBranch(array $node, mixed $input, WorkflowContext $context, array &$steps): mixed
    {
        $selected = $this->invoke($node['decider'], $input, $context);
        $key = is_bool($selected) ? (int) $selected : $selected;

        if (! is_int($key) && ! is_string($key)) {
            throw new RuntimeException("Workflow branch [{$node['name']}] must select a string, integer, or boolean path.");
        }

        if (! array_key_exists($key, $node['branches'])) {
            throw new RuntimeException("Workflow branch [{$node['name']}] selected missing path [{$selected}].");
        }

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'branch',
            'name' => $node['name'],
            'input' => $input,
            'selected' => $selected,
        ];

        return $this->runNodes($node['branches'][$key]->nodes, $input, $context, $steps);
    }

    /**
     * Execute a parallel node and collect each declared result.
     *
     * @param array<string, mixed> $node
     *        Parallel node definition.
     * @param mixed $input Input passed to each parallel child workflow.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @return array<string|int, mixed> Results keyed like the declared parallel steps.
     */
    protected function runParallel(array $node, mixed $input, WorkflowContext $context, array &$steps): array
    {
        $results = [];

        foreach ($node['steps'] as $key => $workflow) {
            $childSteps = [];
            $results[$key] = $this->runNodes($workflow->nodes, $input, $context, $childSteps);

            foreach ($childSteps as $childStep) {
                $steps[] = [
                    ...$childStep,
                    'step' => count($steps) + 1,
                    'parallel' => $node['name'],
                    'parallel_key' => $key,
                ];
            }
        }

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'parallel',
            'name' => $node['name'],
            'input' => $input,
            'output' => $results,
        ];

        return $results;
    }

    /**
     * Execute a loop node until its condition is met or its iteration limit is reached.
     *
     * @param array<string, mixed> $node
     *        Loop node definition.
     * @param mixed $input Initial loop input.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @return mixed Final loop output.
     */
    protected function runLoop(array $node, mixed $input, WorkflowContext $context, array &$steps): mixed
    {
        $current = $input;
        $iterations = 0;

        while ($iterations < $node['max_iterations']) {
            $iterations++;
            $current = $this->runNodes($node['step']->nodes, $current, $context, $steps);

            if ($this->invoke($node['condition'], $current, $context, $iterations) === true) {
                $steps[] = [
                    'step' => count($steps) + 1,
                    'type' => 'loop',
                    'name' => $node['name'],
                    'iterations' => $iterations,
                    'completed' => true,
                    'output' => $current,
                ];

                return $current;
            }
        }

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'loop',
            'name' => $node['name'],
            'iterations' => $iterations,
            'completed' => false,
            'output' => $current,
        ];

        return $current;
    }

    /**
     * Execute a foreach node and collect keyed results for every item.
     *
     * @param array<string, mixed> $node
     *        Foreach node definition.
     * @param mixed $input Input used to resolve the iterable items.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps
     *        Step history passed by reference and appended during execution.
     * @return array<string|int, mixed> Results keyed like the iterable items.
     */
    protected function runForeach(array $node, mixed $input, WorkflowContext $context, array &$steps): array
    {
        $items = $this->invoke($node['items'], $input, $context);

        if (! is_iterable($items)) {
            throw new RuntimeException("Workflow foreach [{$node['name']}] must resolve to an iterable value.");
        }

        $results = [];

        foreach ($items as $key => $item) {
            $childContext = new WorkflowContext([
                ...$context->all(),
                'foreach_key' => $key,
                'foreach_input' => $input,
            ]);

            $childSteps = [];
            $results[$key] = $this->runNodes($node['step']->nodes, $item, $childContext, $childSteps);

            foreach ($childSteps as $childStep) {
                $steps[] = [
                    ...$childStep,
                    'step' => count($steps) + 1,
                    'foreach' => $node['name'],
                    'foreach_key' => $key,
                ];
            }
        }

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'foreach',
            'name' => $node['name'],
            'input' => $input,
            'output' => $results,
        ];

        return $results;
    }

    /**
     * Execute or suspend a human approval node.
     *
     * @param array<string, mixed> $node Approval node definition.
     * @param mixed $input Current workflow input.
     * @param WorkflowContext $context Shared workflow context.
     * @param array<int, array<string, mixed>> $steps Step history passed by reference.
     * @param int $nodeIndex Current node index used for resume snapshots.
     * @return mixed Current input when approved or a suspension marker when approval is pending.
     */
    protected function runApproval(array $node, mixed $input, WorkflowContext $context, array &$steps, int $nodeIndex): mixed
    {
        $approvals = $context->get('_approvals', []);

        if (is_array($approvals) && array_key_exists($node['name'], $approvals)) {
            $steps[] = [
                'step' => count($steps) + 1,
                'type' => 'approval',
                'name' => $node['name'],
                'status' => 'approved',
                'approval' => $approvals[$node['name']],
            ];

            return $input;
        }

        $steps[] = [
            'step' => count($steps) + 1,
            'type' => 'approval',
            'name' => $node['name'],
            'status' => 'awaiting_approval',
            'prompt' => $node['prompt'],
            'metadata' => $node['metadata'],
        ];

        return new WorkflowSuspension(new WorkflowSnapshot(
            id: $this->newSnapshotId(),
            workflow: $this->name ?? static::class,
            status: 'awaiting_approval',
            nodeIndex: $nodeIndex,
            data: $input,
            context: $context->all(),
            steps: $steps,
            approval: [
                'name' => $node['name'],
                'prompt' => $node['prompt'],
                'metadata' => $node['metadata'],
            ],
        ));
    }

    /**
     * Normalize a fluent step definition into a step name and handler.
     *
     * @param string|callable $nameOrStep Step name, step class name, or callable handler.
     * @param string|callable|null $step Optional handler when the first argument is a step name.
     * @return array{0: string, 1: mixed}
     */
    protected function normalizeNamedHandler(string|callable $nameOrStep, string|callable|null $step): array
    {
        if ($step !== null) {
            if (! is_string($nameOrStep)) {
                throw new InvalidArgumentException('Named workflow steps must pass the step name as a string.');
            }

            return [(string) $nameOrStep, $step];
        }

        if (is_string($nameOrStep) && class_exists($nameOrStep)) {
            return [$this->shortName($nameOrStep), $nameOrStep];
        }

        if (is_callable($nameOrStep)) {
            return ['step_'.(count($this->nodes) + 1), $nameOrStep];
        }

        throw new InvalidArgumentException('Workflow step must be a callable or a class name implementing Step.');
    }

    /**
     * Normalize a workflow, callable, class name, or list of steps into a Workflow instance.
     *
     * @param mixed $step Workflow, callable, Step class name, or array of step handlers.
     * @param string|null $name Optional name used when wrapping a single step.
     * @return Workflow
     */
    protected function normalizePipeline(mixed $step, ?string $name = null): Workflow
    {
        if ($step instanceof Workflow) {
            return $step;
        }

        $workflow = new Workflow($name);

        if (is_array($step)) {
            foreach ($step as $handler) {
                $workflow->then($handler);
            }

            return $workflow;
        }

        if ($name !== null) {
            return $workflow->then($name, $step);
        }

        return $workflow->then($step);
    }

    /**
     * Invoke a workflow handler with the current input and context.
     *
     * @param mixed $handler Callable handler or class implementing Step.
     * @param mixed $input Current handler input.
     * @param WorkflowContext $context Shared workflow context.
     * @param int|null $iteration Current loop iteration when invoked by loopUntil.
     * @return mixed Handler output.
     */
    protected function invoke(mixed $handler, mixed $input, WorkflowContext $context, ?int $iteration = null): mixed
    {
        if (is_string($handler) && class_exists($handler)) {
            $handler = new $handler();
        }

        if ($handler instanceof Step) {
            return $handler->handle($input, $context);
        }

        if ($handler instanceof Closure || is_callable($handler)) {
            return $handler($input, $context, $iteration);
        }

        throw new InvalidArgumentException('Workflow handler must be callable or implement Step.');
    }

    /**
     * Resolve a class basename for use as a default step name.
     *
     * @param string $class Fully qualified class name.
     * @return string Class basename or the original class string when parsing fails.
     */
    protected function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    protected function workflowName(): string
    {
        return $this->name ?? static::class;
    }

    protected function traceManager(): ?TraceManager
    {
        if ($this->traceManager) {
            return $this->traceManager;
        }

        if (! function_exists('app')) {
            return null;
        }

        try {
            return app(TraceManager::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Validate a payload against the workflow's simple schema rules.
     *
     * @param mixed $payload Payload being validated.
     * @param array<string, string>|null $schema Schema rules to apply.
     * @param string $label Payload label used in exception messages.
     * @return void
     */
    protected function validateSchema(mixed $payload, ?array $schema, string $label): void
    {
        if ($schema === null) {
            return;
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException("Workflow {$label} must be an array to validate schema rules.");
        }

        foreach ($schema as $field => $rules) {
            $ruleList = array_filter(explode('|', $rules));
            $exists = array_key_exists($field, $payload);
            $value = $payload[$field] ?? null;

            if (in_array('required', $ruleList, true) && ! $exists) {
                throw new InvalidArgumentException("Workflow {$label} field [{$field}] is required.");
            }

            if (! $exists || $value === null) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                if (! $this->passesSchemaRule($value, $rule)) {
                    throw new InvalidArgumentException("Workflow {$label} field [{$field}] must be {$rule}.");
                }
            }
        }
    }

    /**
     * Determine whether a value satisfies one simple schema rule.
     *
     * @param mixed $value Value to check.
     * @param string $rule Rule name.
     * @return bool
     */
    protected function passesSchemaRule(mixed $value, string $rule): bool
    {
        return match ($rule) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'numeric', 'number' => is_int($value) || is_float($value),
            'array' => is_array($value),
            'boolean', 'bool' => is_bool($value),
            default => throw new InvalidArgumentException("Unsupported workflow schema rule [{$rule}]."),
        };
    }

    /**
     * Generate a unique snapshot id.
     *
     * @return string
     */
    protected function newSnapshotId(): string
    {
        return 'workflow_'.str_replace('.', '', uniqid('', true));
    }
}
