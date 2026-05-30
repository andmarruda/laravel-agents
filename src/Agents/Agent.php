<?php

namespace Andmarruda\LaravelAgents\Agents;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\Data\AgentResponse;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Tools\ToolBag;

abstract class Agent
{
    protected ?ModelRouter $modelRouter = null;

    protected ?string $name = null;

    protected ?string $description = null;

    protected ?string $instructions = null;

    protected ?string $model = null;

    protected ToolBag $tools;

    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    protected bool $configured = false;

    protected int $maxToolSteps = 4;

    public function __construct()
    {
        $this->tools = new ToolBag();
    }

    abstract public function configure(): void;

    public function bootAgent(): void
    {
        if ($this->configured) {
            return;
        }

        $this->configure();
        $this->configured = true;
    }

    public function setModelRouter(ModelRouter $modelRouter): static
    {
        $this->modelRouter = $modelRouter;

        return $this;
    }

    public function generate(string $input, array $context = []): AgentResponse
    {
        $this->bootAgent();
        $messages = $this->messages($input, $context);
        $steps = [];

        for ($step = 1; $step <= $this->maxToolSteps + 1; $step++) {
            $response = $this->modelRouter()
                ->for($this->model)
                ->generate($messages, $this->options);

            $toolCall = $this->parseToolCall($response->json());

            if (! $toolCall) {
                return new AgentResponse($response->content, $steps, [
                    'agent' => $this->name(),
                    'model' => $response->model,
                    'provider' => $response->provider,
                    'usage' => $response->usage,
                    'tool_steps' => count($steps),
                ]);
            }

            $tool = $this->tools->get($toolCall['tool']);
            $toolResult = $tool->handle($toolCall['input']);
            $steps[] = [
                'step' => $step,
                'action' => 'tool',
                'tool' => $tool->name(),
                'input' => $toolCall['input'],
                'result' => $this->normalizeToolResult($toolResult),
            ];

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = [
                'role' => 'user',
                'content' => 'Tool result for '.$tool->name().': '.json_encode($steps[array_key_last($steps)]['result'], JSON_UNESCAPED_SLASHES),
            ];
        }

        return new AgentResponse($response->content, $steps, [
            'agent' => $this->name(),
            'model' => $response->model,
            'provider' => $response->provider,
            'usage' => $response->usage,
            'tool_steps' => count($steps),
            'max_tool_steps_reached' => true,
        ]);
    }

    public function name(): string
    {
        return $this->name ?? static::class;
    }

    public function descriptionText(): string
    {
        return $this->description ?? '';
    }

    public function tools(): ToolBag
    {
        return $this->tools;
    }

    protected function nameAs(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    protected function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    protected function instructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    protected function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @param array<int, class-string<Tool>|Tool> $tools
     */
    protected function withTools(array $tools): static
    {
        $this->tools = new ToolBag($tools);

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    protected function maxToolSteps(int $maxToolSteps): static
    {
        $this->maxToolSteps = $maxToolSteps;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{role: string, content: string}>
     */
    protected function messages(string $input, array $context = []): array
    {
        $system = trim(implode("\n\n", array_filter([
            $this->instructions,
            $this->tools->isEmpty() ? null : 'Available tools: '.$this->tools->describe()."\n".
                'To call a tool, respond with strict JSON only: {"action":"tool","tool":"tool_name","input":{}}. '.
                'After receiving the tool result, continue with the final answer or another tool call.',
            $context === [] ? null : 'Context: '.json_encode($context, JSON_UNESCAPED_SLASHES),
        ])));

        return array_values(array_filter([
            $system === '' ? null : ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $input],
        ]));
    }

    protected function modelRouter(): ModelRouter
    {
        if (! $this->modelRouter) {
            $this->modelRouter = app(ModelRouter::class);
        }

        return $this->modelRouter;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{tool: string, input: array<string, mixed>}|null
     */
    protected function parseToolCall(?array $json): ?array
    {
        if (($json['action'] ?? null) !== 'tool') {
            return null;
        }

        if (! is_string($json['tool'] ?? null)) {
            return null;
        }

        $input = $json['input'] ?? [];

        return [
            'tool' => $json['tool'],
            'input' => is_array($input) ? $input : [],
        ];
    }

    protected function normalizeToolResult(mixed $result): mixed
    {
        if (is_scalar($result) || $result === null || is_array($result)) {
            return $result;
        }

        return json_decode(json_encode($result, JSON_UNESCAPED_SLASHES) ?: 'null', true);
    }
}
