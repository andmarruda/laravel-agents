# Laravel Agents

Laravel-native AI agents inspired by Mastra, built around a small multimodal kernel: model routing, image generation, worker agents, supervisor orchestration, tools, and provider adapters.

This package is in early alpha. The current implementation is intentionally focused on the first useful slice: running agents and letting a supervisor agent decide which worker should act next.

## What Works Now

- Model routing with `provider/model` names.
- OpenAI, Anthropic/Claude, and Fireworks AI model adapters.
- Image generation routing with an OpenAI image adapter.
- A small `AgentKernel` for text and image capabilities.
- Worker agents using the base `Agent` class.
- Manager-style orchestration using `SupervisorAgent`.
- Basic `AgentResponse` metadata and supervisor step history.
- Tool definitions and JSON-based tool execution loops.
- Ports & Adapters boundary for model providers.
- Laravel package auto-discovery and publishable config.

## Planned Next

- Persistent memory.
- Deterministic workflows.
- MCP client/server support.
- Observability, traces, and usage tracking.
- RAG with embeddings and vector stores.
- Guardrails for input, output, and tool execution.
- Streaming and structured output helpers.

See [ROADMAP.md](ROADMAP.md) for the version plan.

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

## Current Limitations

- Memory is not persisted yet.
- Workflows are not implemented yet.
- Streaming is not implemented yet.
- Image generation currently ships with an OpenAI adapter only.
- Supervisor decisions rely on the model returning valid JSON.

These are deliberate alpha constraints. The current core is meant to validate the package shape inside a real Laravel project before adding heavier modules.

## Testing

Run the automated test suite:

```bash
composer test
```

The initial suite covers model routing, image routing, DTOs, tools, worker agents, kernel capability routing, and supervisor orchestration without calling external AI APIs.

## Documentation

- Portuguese usage guide: [docs/usage/pt-BR.md](docs/usage/pt-BR.md)
- English usage guide: [docs/usage/en.md](docs/usage/en.md)
- Architecture notes: [docs/architecture/evolutionary-architecture.md](docs/architecture/evolutionary-architecture.md)
- Roadmap: [ROADMAP.md](ROADMAP.md)
