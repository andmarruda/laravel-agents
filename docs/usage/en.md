# Usage Guide: Laravel Agents

This guide shows how to test the current alpha implementation in a Laravel project.

This version focuses on the orchestration core:

- call models with `provider/model` names;
- create worker agents;
- create a supervisor/manager agent;
- delegate tasks between agents;
- run deterministic workflows for explicit business processes;
- receive a final answer and step history.

For MCP usage, see [MCP Usage Guide](mcp-en.md). It covers remote MCP clients, a Laravel MCP server, controller and route adapters, authentication hooks, and per-agent MCP permissions.

## 1. Installation

If the package is already available through Composer/Packagist, run this in a Laravel project:

```bash
composer require andmarruda/laravel-agents
```

For local alpha testing, add a path repository to your Laravel app `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-agents",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require it:

```bash
composer require andmarruda/laravel-agents:@dev
```

Publish the config:

```bash
php artisan vendor:publish --tag=agents-config
```

Configure your `.env`:

```env
AGENTS_DEFAULT_MODEL=openai/gpt-4.1-mini
AGENTS_MODEL_TIMEOUT=60
AGENTS_MODEL_RETRY_TIMES=2
AGENTS_MODEL_RETRY_SLEEP=250
AGENTS_IMAGE_MODEL=openai/gpt-image-1
AGENTS_IMAGE_SIZE=1024x1024
AGENTS_IMAGE_DISK=public

OPENAI_API_KEY=
ANTHROPIC_API_KEY=
FIREWORKS_API_KEY=
```

You only need to fill the key for the provider you want to test first.

## 2. Testing A Model Directly

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::model('openai/gpt-4.1-mini')->generate([
    ['role' => 'user', 'content' => 'Answer in one sentence: what is an AI agent?'],
]);

echo $response->content;
```

Model name examples:

```php
LaravelAgents::model('openai/gpt-4.1-mini');
LaravelAgents::model('anthropic/claude-sonnet-4');
LaravelAgents::model('fireworks/accounts/fireworks/models/llama-v3p1-70b-instruct');
```

## 2.1. Generating Images

Images are treated as a capability, not as plain text output.

```php
use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::image('openai/gpt-image-1')
    ->generate(new ImageGenerationRequest(
        prompt: 'A clean Laravel agent orchestration diagram',
        size: '1024x1024',
    ));

$url = $response->firstUrl();
$base64 = $response->firstBase64();
```

## 3. Creating A Worker Agent

Create `app/Agents/ResearchAgent.php`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ResearchAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('researcher');
        $this->description('Finds facts, constraints, and useful context.');
        $this->instructions('You are a careful research agent. Be concise and cite uncertainty.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

Use the agent:

```php
use App\Agents\ResearchAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ResearchAgent::class)
    ->generate('Research the trade-offs of using AI agents in a Laravel application.');

echo $response->content;
```

## 4. Creating Additional Workers

Writer example:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class WriterAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('writer');
        $this->description('Turns notes and context into clear text.');
        $this->instructions('Write clearly, practically, and directly.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

Reviewer example:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ReviewerAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('reviewer');
        $this->description('Reviews answers for gaps, risks, and inconsistencies.');
        $this->instructions('Review critically. Point out problems and suggest improvements.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

## 5. Creating A Supervisor Agent

Create `app/Agents/ManagerAgent.php`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\SupervisorAgent;

final class ManagerAgent extends SupervisorAgent
{
    public function configure(): void
    {
        $this->nameAs('manager');
        $this->description('Coordinates specialist agents and produces the final answer.');
        $this->instructions('Choose the best worker for each step. Stop when the task is complete.');
        $this->model('anthropic/claude-sonnet-4');
        $this->maxSteps(8);
        $this->withAgents([
            ResearchAgent::class,
            WriterAgent::class,
            ReviewerAgent::class,
        ]);
    }
}
```

Use the supervisor:

```php
use App\Agents\ManagerAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ManagerAgent::class)
    ->generate('Research, write, and review a launch plan for a Laravel AI agents package.');

echo $response->content;

foreach ($response->steps as $step) {
    logger()->info('Agent step', $step);
}
```

## 6. How The Supervisor Decides

On each loop, the supervisor asks the model for a strict JSON decision.

To delegate:

```json
{"action":"delegate","agent":"researcher","task":"Research market context"}
```

To finish:

```json
{"action":"final","answer":"Final answer here"}
```

If the model returns invalid JSON, the package throws an exception. In this alpha phase, that is expected: guardrails and retry/correction loops are planned for a future version.

## 7. Passing Context

```php
$response = LaravelAgents::agent(ManagerAgent::class)
    ->generate('Analyze this alpha project.', [
        'project_id' => $project->id,
        'user_id' => $user->id,
        'constraints' => [
            'no persistent memory yet',
            'workflows run synchronously in this alpha',
        ],
    ]);
```

In supervisor runs, this context is also passed down to workers together with `previous_steps`.

## 8. Using Model Options

Inside an agent:

```php
$this->options([
    'temperature' => 0.2,
    'max_tokens' => 1200,
]);
```

The options `timeout`, `retry_times`, and `retry_sleep` are treated as runtime config and are not sent in the model payload.

## 8.1. Executing Tools

Agents with tools can request a real action by returning strict JSON:

```json
{"action":"tool","tool":"generate_image","input":{"prompt":"A launch post image","size":"1024x1024"}}
```

The agent executes the tool, injects the result into the next model call, and continues until it returns a normal final answer or reaches the tool-step limit.

## 9. Deterministic Workflows

Use workflows when you know the process ahead of time and want predictable execution. A workflow can still call agents or tools inside a step, but the route itself is defined by your code.

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;

$response = LaravelAgents::workflow()
    ->named('invoice-review')
    ->then('normalize', function (array $invoice, WorkflowContext $context): array {
        $context->put('currency', 'USD');

        return [
            ...$invoice,
            'total' => (float) $invoice['total'],
        ];
    })
    ->branch(
        fn (array $invoice): string => $invoice['total'] >= 1000 ? 'approval' : 'auto',
        [
            'approval' => fn (array $invoice): array => [...$invoice, 'status' => 'waiting_approval'],
            'auto' => fn (array $invoice): array => [...$invoice, 'status' => 'approved'],
        ],
        'approval_gate',
    )
    ->run(['id' => 10, 'total' => '1250.00']);

$invoice = $response->data;
$steps = $response->steps;
$context = $response->meta['context'];
```

The response contains:

- `data`: the final workflow output;
- `steps`: an ordered history of steps, branches, loops, and fan-out results;
- `meta`: workflow name, step count, and final context.

## 9.1. Reusable Step Classes

For reusable workflow steps, implement `Step`:

```php
<?php

namespace App\Workflows\Steps;

use Andmarruda\LaravelAgents\Workflows\Step;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;

final class AddTaxStep implements Step
{
    public function handle(mixed $input, WorkflowContext $context): array
    {
        return [
            ...$input,
            'total' => $input['total'] * 1.08,
        ];
    }
}
```

Then add it to a workflow:

```php
use App\Workflows\Steps\AddTaxStep;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::workflow()
    ->then(AddTaxStep::class)
    ->run(['total' => 100]);
```

## 9.2. Flow Helpers

```php
LaravelAgents::workflow()
    ->then('normalize', fn (array $input) => $input)
    ->parallel([
        'summary' => fn (array $input) => summarize($input),
        'score' => fn (array $input) => score($input),
    ])
    ->loopUntil(
        fn (array $state) => pollStatus($state),
        fn (array $state) => $state['ready'] === true,
        maxIterations: 5,
    )
    ->forEach(
        fn (array $state) => $state['items'],
        fn (array $item) => processItem($item),
    );
```

- `then()` runs a single step.
- `branch()` chooses one named path.
- `parallel()` runs fan-out/fan-in in declaration order.
- `loopUntil()` repeats a step until the condition is true or the max iteration count is reached.
- `forEach()` processes iterable items and returns keyed results.
- `approval()` suspends a workflow until a human approval value is provided on resume.

## 9.3. Schemas, Snapshots, And Approval

Workflows can validate input and output arrays with simple rules:

```php
$response = LaravelAgents::workflow()
    ->inputSchema(['name' => 'required|string'])
    ->outputSchema(['message' => 'required|string'])
    ->then('greet', fn (array $input): array => ['message' => 'Hello '.$input['name']])
    ->run(['name' => 'Ana']);
```

Approval steps suspend execution and return a `WorkflowSnapshot`:

```php
use Andmarruda\LaravelAgents\Workflows\InMemoryWorkflowStore;

$store = new InMemoryWorkflowStore();

$workflow = LaravelAgents::workflow()
    ->then('prepare', fn (array $input): array => [...$input, 'prepared' => true])
    ->approval('manager_approval', 'Approve this invoice?', ['role' => 'manager'])
    ->then('finish', fn (array $input): array => [...$input, 'finished' => true]);

$response = $workflow->run(['id' => 10], store: $store);

$resumed = $workflow->resume(
    $response->snapshot->id,
    ['approved_by' => auth()->id()],
    $store,
);
```

The suspended response has `status`, `snapshot`, `steps`, and approval metadata in `meta`.

## 9.4. Queue Execution

Class-based workflows can be dispatched as Laravel queue jobs:

```php
final class InvoiceWorkflow extends \Andmarruda\LaravelAgents\Workflows\Workflow
{
    public function __construct()
    {
        parent::__construct('invoice-workflow');

        $this
            ->then(NormalizeInvoice::class)
            ->approval('manager_approval', 'Approve this invoice?')
            ->then(SaveInvoice::class);
    }
}

(new InvoiceWorkflow())->dispatch(['id' => 10]);
```

The generated job implements Laravel's `ShouldQueue` contract. Closure-based steps are best kept for synchronous runs; class-based steps are safer for queue serialization.

## 10. Architecture

The package uses Ports & Adapters for model providers:

- text port: `Andmarruda\LaravelAgents\Ports\ModelPort`;
- image port: `Andmarruda\LaravelAgents\Ports\ImageGenerationPort`;
- adapters: `OpenAiModelAdapter`, `AnthropicModelAdapter`, `FireworksModelAdapter`;
- composition: `ModelRouter`, `ImageRouter`, and `AgentKernel`.

The rest of the package should evolve through small modules:

- `MemoryPort`;
- `WorkflowStorePort`;
- `McpClientPort`;
- `TracePort`;
- `EmbeddingPort`;
- `VectorStorePort`;
- `GuardrailPort`.

Read also: [../architecture/evolutionary-architecture.md](../architecture/evolutionary-architecture.md).

## 11. Alpha Limitations

- There is no persistent memory.
- `InMemoryWorkflowStore` is for tests and examples. Production apps should bind a persistent workflow store.
- Closure-based workflow steps are best for synchronous runs; class-based workflows are safest for queued execution.
- There is no streaming.
- Image generation ships with an OpenAI adapter in this first version.
- The supervisor depends on valid JSON from the model.

The goal now is to test the core ergonomics inside a real Laravel project before adding heavier modules.
