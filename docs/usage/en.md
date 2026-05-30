# Usage Guide: Laravel Agents

This guide shows how to test the current alpha implementation in a Laravel project.

This version focuses on the orchestration core:

- call models with `provider/model` names;
- create worker agents;
- create a supervisor/manager agent;
- delegate tasks between agents;
- receive a final answer and step history.

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
            'no workflows yet',
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

## 9. Architecture

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

## 10. Alpha Limitations

- There is no persistent memory.
- There are no workflows.
- There is no streaming.
- Image generation ships with an OpenAI adapter in this first version.
- The supervisor depends on valid JSON from the model.

The goal now is to test the core ergonomics inside a real Laravel project before adding heavier modules.
