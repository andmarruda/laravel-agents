# Laravel Agents

Laravel-native AI agents inspired by Mastra, built around a small multimodal kernel: model routing, image generation, worker agents, supervisor orchestration, tools, and provider adapters.

This package is in early alpha. The current implementation is intentionally focused on useful orchestration primitives: running agents, letting a supervisor decide which worker should act next, and running deterministic workflows when a process should be explicit and repeatable.

## What Works Now

- Model routing with `provider/model` names.
- OpenAI, Anthropic/Claude, and Fireworks AI model adapters.
- Image generation routing with an OpenAI image adapter.
- A small `AgentKernel` for text and image capabilities.
- Worker agents using the base `Agent` class.
- Manager-style orchestration using `SupervisorAgent`.
- Basic `AgentResponse` metadata and supervisor step history.
- Tool definitions and JSON-based tool execution loops.
- Deterministic workflows with steps, branches, parallel fan-out, loops, and forEach processing.
- Workflow input/output schemas, queued jobs, suspend/resume snapshots, and human approval steps.
- Production observability with traces, spans, model usage, cost metadata, lifecycle events, storage, and optional JSON dashboard routes.
- Input, output, and tool guardrails with validation, correction retries, permissions, redaction, and human approval.
- Retrieval-augmented generation with loaders, deterministic chunking, OpenAI embeddings, pgvector, Qdrant, retriever tools, and workflow steps.
- Ports & Adapters boundary for model providers.
- Laravel package auto-discovery and publishable config.

## Planned Next

- Streaming and structured output helpers.

See [ROADMAP.md](ROADMAP.md) for the version plan.

RAG documentation: [English](docs/usage/rag-en.md) | [Português](docs/usage/rag-pt-BR.md).

Guardrails documentation: [English](docs/usage/guardrails-en.md) | [Português](docs/usage/guardrails-pt-BR.md).

## Installation

If the package is already available through Composer/Packagist, require it in a Laravel app:

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

Publish and run the package migrations when using memory or observability storage:

```bash
php artisan vendor:publish --tag=agents-migrations
php artisan migrate
```

Configure at least one provider:

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

## Model Names

Use the `provider/model` format:

```php
LaravelAgents::model('openai/gpt-4.1-mini');
LaravelAgents::model('anthropic/claude-sonnet-4');
LaravelAgents::model('fireworks/accounts/fireworks/models/llama-v3p1-70b-instruct');
```

The model router depends on the `ModelPort` interface. Provider integrations live in adapters:

- `OpenAiModelAdapter`
- `AnthropicModelAdapter`
- `FireworksModelAdapter`

## Image Generation

Image generation is exposed as a capability instead of pretending images are just text:

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

You can also go through the kernel:

```php
$image = LaravelAgents::kernel()
    ->image()
    ->generate(new ImageGenerationRequest(prompt: 'A product launch illustration'));
```

## Worker Agent

Create a worker agent in your Laravel app, for example `app/Agents/ResearchAgent.php`:

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

Run it:

```php
use App\Agents\ResearchAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ResearchAgent::class)
    ->generate('Summarize the trade-offs of using AI agents in a Laravel app.');

echo $response->content;
```

## Supervisor Agent

A supervisor coordinates worker agents. It asks the model for a strict JSON decision on every step:

- delegate to a worker;
- or return the final answer.

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
        $this->instructions('Use the best worker for each step. Stop when the task is complete.');
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

Run it:

```php
use App\Agents\ManagerAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ManagerAgent::class)
    ->generate('Research, write, and review a launch plan for a Laravel AI package.');

echo $response->content;

$steps = $response->steps;
```

The supervisor expects one of these JSON shapes from the model:

```json
{"action":"delegate","agent":"researcher","task":"Find market context"}
```

```json
{"action":"final","answer":"Final answer here"}
```

## Passing Context

You can pass runtime context to an agent:

```php
$response = LaravelAgents::agent(ResearchAgent::class)
    ->generate('Analyze this project.', [
        'project_id' => $project->id,
        'user_id' => $user->id,
        'constraints' => ['alpha', 'no persistent memory yet'],
    ]);
```

For supervisor runs, context is also passed down to worker agents along with `previous_steps`.

## Tool Execution

Agents with tools can ask the kernel to execute a real action by returning strict JSON:

```json
{"action":"tool","tool":"generate_image","input":{"prompt":"A launch post image","size":"1024x1024"}}
```

The agent executes the tool, injects the tool result into the next model call, and then continues until it returns a normal final answer or reaches the tool-step limit.

## Observability

Observability is opt-in. Agent runs, direct model calls, tool calls, supervisor delegation, and workflow nodes emit traces and spans when enabled. Responses include `trace_id` metadata when tracing is active:

```php
$response = LaravelAgents::agent(ResearchAgent::class)
    ->generate('Find useful context.');

$traceId = $response->meta['trace_id'];
```

Configure storage and the optional JSON dashboard in `config/agents.php`:

```env
AGENTS_OBSERVABILITY_ENABLED=true
AGENTS_OBSERVABILITY_STORE=database
AGENTS_OBSERVABILITY_DASHBOARD_ENABLED=true
AGENTS_OBSERVABILITY_DASHBOARD_ROUTE=/agents/observability/traces
```

The dashboard exposes:

- `GET /agents/observability/traces`
- `GET /agents/observability/traces/{traceId}`

The package also dispatches Laravel lifecycle events for agent, model, tool, and workflow activity, and includes an OpenTelemetry-shaped exporter contract for forwarding traces to your own telemetry pipeline.

## RAG

Index Laravel-friendly document loaders into memory, PostgreSQL/pgvector, Qdrant, or a custom vector store:

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;

$summary = LaravelAgents::indexer()->index(
    new FileDocumentLoader(storage_path('knowledge/guide.md')),
    namespace: 'product-docs',
);

$results = LaravelAgents::retriever(namespace: 'product-docs')
    ->retrieve('How do agents call tools?', limit: 5);
```

Publish the dedicated PostgreSQL/pgvector migration only when using that store:

```bash
php artisan vendor:publish --tag=agents-rag-pgvector-migrations
php artisan migrate
```

See the complete [RAG usage guide](docs/usage/rag-en.md) for loaders, metadata filters, retriever tools, workflow steps, pgvector, Qdrant, and custom adapters.

## Deterministic Workflows

Use workflows when a process should follow known steps instead of asking a model to choose the next action. Workflows run synchronously in this first slice and return structured output, step history, and final context.

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

Reusable step classes implement `Andmarruda\LaravelAgents\Workflows\Step`:

```php
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

Available flow helpers:

- `then()` for one deterministic step after another.
- `branch()` for named paths.
- `parallel()` for deterministic fan-out/fan-in in declaration order.
- `loopUntil()` for bounded loops.
- `forEach()` for processing iterable items.
- `approval()` for human-in-the-loop suspension and resume.

Workflows can validate input and output payloads:

```php
$response = LaravelAgents::workflow()
    ->inputSchema(['name' => 'required|string'])
    ->outputSchema(['message' => 'required|string'])
    ->then('greet', fn (array $input): array => ['message' => 'Hello '.$input['name']])
    ->run(['name' => 'Ana']);
```

Human approval steps suspend the workflow and return a resumable snapshot:

```php
use Andmarruda\LaravelAgents\Workflows\InMemoryWorkflowStore;

$store = new InMemoryWorkflowStore();

$response = LaravelAgents::workflow()
    ->then('prepare', fn (array $input): array => [...$input, 'prepared' => true])
    ->approval('manager_approval', 'Approve this invoice?', ['role' => 'manager'])
    ->then('finish', fn (array $input): array => [...$input, 'finished' => true])
    ->run(['id' => 10], store: $store);

if ($response->status === 'awaiting_approval') {
    $snapshotId = $response->snapshot->id;
}

$resumed = LaravelAgents::workflow()
    ->then('prepare', fn (array $input): array => [...$input, 'prepared' => true])
    ->approval('manager_approval', 'Approve this invoice?', ['role' => 'manager'])
    ->then('finish', fn (array $input): array => [...$input, 'finished' => true])
    ->resume($snapshotId, ['approved_by' => auth()->id()], $store);
```

Class-based workflows can be queued. The job implements Laravel's `ShouldQueue` contract:

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

## Current Limitations

- Closure-based workflow steps are best for synchronous runs; class-based workflows are the safest option for queued execution.
- `InMemoryWorkflowStore` is useful for tests and local examples. Production apps should bind a persistent `WorkflowStore`.
- Streaming is not implemented yet.
- Image generation currently ships with an OpenAI adapter only.
- Supervisor decisions rely on the model returning valid JSON.

These are deliberate alpha constraints. The current core is meant to validate the package shape inside a real Laravel project before adding heavier modules.

## Testing

Run the automated test suite:

```bash
composer test
```

The initial suite covers model routing, image routing, DTOs, tools, worker agents, deterministic workflows, kernel capability routing, and supervisor orchestration without calling external AI APIs.

## Documentation

- Portuguese usage guide: [docs/usage/pt-BR.md](docs/usage/pt-BR.md)
- English usage guide: [docs/usage/en.md](docs/usage/en.md)
- Memory (English): [docs/usage/memory-en.md](docs/usage/memory-en.md)
- Memory (Português): [docs/usage/memory-pt-BR.md](docs/usage/memory-pt-BR.md)
- Architecture notes: [docs/architecture/evolutionary-architecture.md](docs/architecture/evolutionary-architecture.md)
- Roadmap: [ROADMAP.md](ROADMAP.md)

## Support This Project

Laravel Agents is open-source and free to use. If this package saves you time, helps you ship faster, or simply sparks an idea, consider buying a coffee — it keeps the project alive and motivates new features, adapters, and documentation.

[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-support-yellow?logo=buy-me-a-coffee&logoColor=white)](https://buymeacoffee.com/andmarruda)
