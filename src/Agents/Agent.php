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

        $response = $this->modelRouter()
            ->for($this->model)
            ->generate($this->messages($input, $context), $this->options);

        return new AgentResponse($response->content, meta: [
            'agent' => $this->name(),
            'model' => $response->model,
            'provider' => $response->provider,
            'usage' => $response->usage,
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

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{role: string, content: string}>
     */
    protected function messages(string $input, array $context = []): array
    {
        $system = trim(implode("\n\n", array_filter([
            $this->instructions,
            $this->tools->isEmpty() ? null : 'Available tools: '.$this->tools->describe(),
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
}
