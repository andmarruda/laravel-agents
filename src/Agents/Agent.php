<?php

namespace Andmarruda\LaravelAgents\Agents;

use Andmarruda\LaravelAgents\Contracts\Memory\LongTermMemory;
use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;
use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\Data\AgentResponse;
use Andmarruda\LaravelAgents\Data\ModelResponse;
use Andmarruda\LaravelAgents\Guardrails\CorrectionPolicy;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Enums\GuardrailDecision;
use Andmarruda\LaravelAgents\Guardrails\Events\GuardrailRetrying;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\GuardrailRetryLimitException;
use Andmarruda\LaravelAgents\Guardrails\GuardrailPipeline;
use Andmarruda\LaravelAgents\MCP\Server\McpToolRegistry;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunFailed;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunFinished;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunStarted;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallFailed;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallFinished;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallStarted;
use Andmarruda\LaravelAgents\Observability\Events\ToolCallFailed;
use Andmarruda\LaravelAgents\Observability\Events\ToolCallFinished;
use Andmarruda\LaravelAgents\Observability\Events\ToolCallStarted;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Andmarruda\LaravelAgents\Tools\ToolBag;
use Illuminate\Support\Str;
use Throwable;

abstract class Agent
{
    protected ?ModelRouter $modelRouter = null;

    protected ?McpToolRegistry $mcpToolRegistry = null;

    protected ?TraceManager $traceManager = null;

    protected ?GuardrailPipeline $guardrailPipeline = null;

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

    /**
     * @var array<int, string>
     */
    protected array $mcpServers = [];

    /**
     * @var array<int, string>
     */
    protected array $allowedMcpTools = [];

    /**
     * @var array<int, object>
     */
    protected array $inputGuardrails = [];

    /**
     * @var array<int, object>
     */
    protected array $outputGuardrails = [];

    /**
     * @var array<int, object>
     */
    protected array $toolGuardrailPolicies = [];

    /**
     * @var array<int, object>
     */
    protected array $runGuardrails = [];

    /**
     * @var array<int, object>
     */
    protected array $runToolGuardrails = [];

    /**
     * @var array<string, mixed>
     */
    protected array $guardrailContext = [];

    protected CorrectionPolicy $correctionPolicy;

    protected int $maxToolSteps = 4;

    protected bool $shortTermMemoryEnabled = false;

    protected bool $longTermMemoryEnabled = false;

    protected int $memoryTtlSeconds = 3600;

    protected ?string $sessionId = null;

    public function __construct()
    {
        $this->tools = new ToolBag();
        $this->correctionPolicy = new CorrectionPolicy();
    }

    abstract public function configure(): void;

    public function bootAgent(): void
    {
        if ($this->configured) {
            return;
        }

        $this->configure();
        $this->loadMcpTools();
        $this->configured = true;
    }

    public function setModelRouter(ModelRouter $modelRouter): static
    {
        $this->modelRouter = $modelRouter;

        return $this;
    }

    public function setMcpToolRegistry(McpToolRegistry $mcpToolRegistry): static
    {
        $this->mcpToolRegistry = $mcpToolRegistry;

        return $this;
    }

    public function setTraceManager(?TraceManager $traceManager): static
    {
        $this->traceManager = $traceManager;

        return $this;
    }

    public function setGuardrailPipeline(?GuardrailPipeline $pipeline): static
    {
        $this->guardrailPipeline = $pipeline;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withGuardrailContext(array $context): static
    {
        $this->guardrailContext = [...$this->guardrailContext, ...$context];

        return $this;
    }

    /**
     * @param array<int, object> $guardrails
     */
    public function withRunGuardrails(array $guardrails): static
    {
        $this->runGuardrails = [...$this->runGuardrails, ...$guardrails];

        return $this;
    }

    /**
     * @param array<int, object> $guardrails
     */
    public function withRunToolGuardrails(array $guardrails): static
    {
        $this->runToolGuardrails = [...$this->runToolGuardrails, ...$guardrails];

        return $this;
    }

    public function continueSession(string $sessionId): static
    {
        $this->sessionId = $sessionId;
        $this->shortTermMemoryEnabled = true;

        return $this;
    }

    public function generate(string $input, array $context = []): AgentResponse
    {
        $this->bootAgent();
        $traceManager = $this->traceManager();
        $trace = $traceManager?->startTrace('agent.run', [
            'agent.name' => $this->name(),
            'agent.class' => static::class,
            'input.length' => strlen($input),
        ]);

        try {
            if ($this->shortTermMemoryEnabled && $this->sessionId === null) {
                $this->sessionId = (string) Str::uuid();
            }

            $input = $this->applyInputGuardrails($input);
            $traceManager?->dispatch(new AgentRunStarted($this->name(), $input, $context));
            $history = $this->loadShortTermHistory();
            $messages = $this->messages($input, $context, $history);
            $steps = [];
            $correctionAttempts = 0;

            for ($step = 1; $step <= $this->maxToolSteps + 1; $step++) {
                [$response, $attempts] = $this->generateWithGuardrails($messages);
                $correctionAttempts += $attempts;

                $toolCall = $this->parseToolCall($response->json());

                if (! $toolCall) {
                    $this->appendToShortTermMemory($input, $response->content);

                    $agentResponse = new AgentResponse($response->content, $steps, [
                        'agent' => $this->name(),
                        'model' => $response->model,
                        'provider' => $response->provider,
                        'usage' => $response->usage,
                        'tool_steps' => count($steps),
                        'correction_attempts' => $correctionAttempts,
                        'trace_id' => $trace?->id,
                    ], $this->sessionId);
                    $traceManager?->dispatch(new AgentRunFinished($this->name(), $agentResponse));
                    $traceManager?->finishTrace($trace, ['agent.tool_steps' => count($steps)]);

                    return $agentResponse;
                }

                $tool = $this->tools->get($toolCall['tool']);
                $toolInput = $this->guardrailPipeline()->run(
                    $toolCall['input'],
                    $this->guardrailContext('tool', $tool, 'before')
                        ->with('tool_schema', $tool->schema())
                        ->with('tool_class', $tool::class),
                    [...$this->toolGuardrailPolicies, ...$this->runToolGuardrails],
                )->value;
                $toolResult = $this->handleToolWithObservability($tool, $toolInput);
                $steps[] = [
                    'step' => $step,
                    'action' => 'tool',
                    'tool' => $tool->name(),
                    'input' => $toolInput,
                    'result' => $this->normalizeToolResult($toolResult),
                ];

                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = [
                    'role' => 'user',
                    'content' => 'Tool result for '.$tool->name().': '.json_encode($steps[array_key_last($steps)]['result'], JSON_UNESCAPED_SLASHES),
                ];
            }

            $this->appendToShortTermMemory($input, $response->content);

            $agentResponse = new AgentResponse($response->content, $steps, [
                'agent' => $this->name(),
                'model' => $response->model,
                'provider' => $response->provider,
                'usage' => $response->usage,
                'tool_steps' => count($steps),
                'correction_attempts' => $correctionAttempts,
                'max_tool_steps_reached' => true,
                'trace_id' => $trace?->id,
            ], $this->sessionId);
            $traceManager?->dispatch(new AgentRunFinished($this->name(), $agentResponse));
            $traceManager?->finishTrace($trace, ['agent.tool_steps' => count($steps)]);

            return $agentResponse;
        } catch (Throwable $throwable) {
            $traceManager?->dispatch(new AgentRunFailed($this->name(), $throwable));
            $traceManager?->failTrace($trace, $throwable);

            throw $throwable;
        } finally {
            $this->clearRunGuardrails();
        }
    }

    public function remember(string $scope, string $key, mixed $value): void
    {
        app(LongTermMemory::class)->store($this->name(), $scope, $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function recall(string $scope, ?string $key = null): array
    {
        return app(LongTermMemory::class)->retrieve($this->name(), $scope, $key);
    }

    public function forget(string $scope, ?string $key = null): void
    {
        app(LongTermMemory::class)->forget($this->name(), $scope, $key);
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
     * @param array<int, object> $guardrails
     */
    protected function guardrails(array $guardrails): static
    {
        $this->inputGuardrails = [...$this->inputGuardrails, ...$guardrails];
        $this->outputGuardrails = [...$this->outputGuardrails, ...$guardrails];

        return $this;
    }

    /**
     * @param array<int, object> $guardrails
     */
    protected function toolGuardrails(array $guardrails): static
    {
        $this->toolGuardrailPolicies = [...$this->toolGuardrailPolicies, ...$guardrails];

        return $this;
    }

    protected function corrections(int $maxAttempts = 2, int $backoffMilliseconds = 0): static
    {
        $this->correctionPolicy = new CorrectionPolicy($maxAttempts, $backoffMilliseconds);

        return $this;
    }

    /**
     * @param array<int, string> $servers
     */
    protected function withMcpServers(array $servers): static
    {
        $this->mcpServers = $servers;

        return $this;
    }

    /**
     * @param array<int, string> $tools
     */
    protected function allowMcpTools(array $tools): static
    {
        $this->allowedMcpTools = $tools;

        return $this;
    }

    protected function enableShortTermMemory(): static
    {
        $this->shortTermMemoryEnabled = true;

        return $this;
    }

    protected function enableLongTermMemory(): static
    {
        $this->longTermMemoryEnabled = true;

        return $this;
    }

    protected function memoryTtl(int $seconds): static
    {
        $this->memoryTtlSeconds = $seconds;

        return $this;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param array<string, mixed> $context
     * @return array<int, array{role: string, content: string}>
     */
    protected function messages(string $input, array $context = [], array $history = []): array
    {
        $system = trim(implode("\n\n", array_filter([
            $this->instructions,
            $this->tools->isEmpty() ? null : 'Available tools: '.$this->tools->describe()."\n".
                'To call a tool, respond with strict JSON only: {"action":"tool","tool":"tool_name","input":{}}. '.
                'After receiving the tool result, continue with the final answer or another tool call.',
            $context === [] ? null : 'Context: '.json_encode($context, JSON_UNESCAPED_SLASHES),
        ])));

        $base = array_values(array_filter([
            $system === '' ? null : ['role' => 'system', 'content' => $system],
        ]));

        return array_merge($base, $history, [['role' => 'user', 'content' => $input]]);
    }

    protected function modelRouter(): ModelRouter
    {
        if (! $this->modelRouter) {
            $this->modelRouter = app(ModelRouter::class);
        }

        return $this->modelRouter;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    protected function generateWithObservability(array $messages, array $options): \Andmarruda\LaravelAgents\Data\ModelResponse
    {
        $traceManager = $this->traceManager();
        $span = $traceManager?->startSpan('model.generate', 'model', [
            'agent.name' => $this->name(),
            'model.requested' => $this->model,
            'model.message_count' => count($messages),
        ]);
        $traceManager?->dispatch(new ModelCallStarted($this->model, $messages, $options));

        try {
            $response = $this->modelRouter()
                ->for($this->model)
                ->generate($messages, $options);
            $traceManager?->finishSpan($span, $traceManager->modelMetadata($response->provider, $response->model, $response->usage));
            $traceManager?->dispatch(new ModelCallFinished($response));

            return $response;
        } catch (Throwable $throwable) {
            $traceManager?->failSpan($span, $throwable);
            $traceManager?->dispatch(new ModelCallFailed($this->model, $throwable));

            throw $throwable;
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{0: ModelResponse, 1: int}
     */
    protected function generateWithGuardrails(array $messages): array
    {
        for ($attempt = 1; $attempt <= $this->correctionPolicy->maxAttempts; $attempt++) {
            $response = $this->generateWithObservability($messages, $this->options);
            $result = $this->guardrailPipeline()->run(
                $response->content,
                $this->guardrailContext('model.output', attempt: $attempt),
                [...$this->outputGuardrails, ...$this->runGuardrails],
            );

            if ($result->decision !== GuardrailDecision::Retry) {
                if ($result->decision === GuardrailDecision::Modify && is_string($result->value)) {
                    $response = new ModelResponse($result->value, $response->model, $response->provider, $response->usage, $response->raw);
                }

                return [$response, $attempt - 1];
            }

            if ($attempt >= $this->correctionPolicy->maxAttempts) {
                throw new GuardrailRetryLimitException($result->violations);
            }

            $this->traceManager()?->dispatch(new GuardrailRetrying($attempt + 1, $result->violations));
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $this->correctionPolicy->prompt($result->violations)];
            $this->correctionPolicy->backoff();
        }

        throw new GuardrailRetryLimitException([]);
    }

    protected function applyInputGuardrails(string $input): string
    {
        $value = $this->guardrailPipeline()->run(
            $input,
            $this->guardrailContext('model.input'),
            [...$this->inputGuardrails, ...$this->runGuardrails],
        )->value;

        if (! is_string($value)) {
            throw new \Andmarruda\LaravelAgents\Guardrails\Exceptions\InvalidGuardrailResultException(
                'Model input guardrails must return a string value.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function handleToolWithObservability(Tool $tool, array $input): mixed
    {
        $traceManager = $this->traceManager();
        $span = $traceManager?->startSpan('tool.call', 'tool', [
            'agent.name' => $this->name(),
            'tool.name' => $tool->name(),
            'tool.input' => $input,
        ]);
        $traceManager?->dispatch(new ToolCallStarted($tool->name(), $input));

        try {
            $result = $tool->handle($input);
            $result = $this->guardrailPipeline()->run(
                $this->normalizeToolResult($result),
                $this->guardrailContext('tool', $tool, 'after')->with('tool_class', $tool::class),
                [...$this->toolGuardrailPolicies, ...$this->runToolGuardrails],
            )->value;
            $traceManager?->finishSpan($span, ['tool.result' => $this->normalizeToolResult($result)]);
            $traceManager?->dispatch(new ToolCallFinished($tool->name(), $result));

            return $result;
        } catch (Throwable $throwable) {
            $traceManager?->failSpan($span, $throwable);
            $traceManager?->dispatch(new ToolCallFailed($tool->name(), $throwable));

            throw $throwable;
        }
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

    protected function guardrailPipeline(): GuardrailPipeline
    {
        if ($this->guardrailPipeline) {
            return $this->guardrailPipeline;
        }

        try {
            return $this->guardrailPipeline = function_exists('app')
                ? app(GuardrailPipeline::class)
                : new GuardrailPipeline(traces: $this->traceManager());
        } catch (Throwable) {
            return $this->guardrailPipeline = new GuardrailPipeline(traces: $this->traceManager());
        }
    }

    protected function guardrailContext(
        string $operation,
        ?Tool $tool = null,
        string $phase = 'before',
        int $attempt = 1,
    ): GuardrailContext {
        return new GuardrailContext(
            operation: $operation,
            metadata: $this->guardrailContext,
            agent: static::class,
            tool: $tool?->name(),
            phase: $phase,
            attempt: $attempt,
        );
    }

    protected function clearRunGuardrails(): void
    {
        $this->runGuardrails = [];
        $this->runToolGuardrails = [];
        $this->guardrailContext = [];
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

    protected function loadMcpTools(): void
    {
        foreach ($this->mcpToolRegistry()->toolsForAgent(static::class, $this->mcpServers, $this->allowedMcpTools) as $tool) {
            $this->tools->add($tool);
        }
    }

    protected function mcpToolRegistry(): McpToolRegistry
    {
        if ($this->mcpToolRegistry) {
            return $this->mcpToolRegistry;
        }

        $this->mcpToolRegistry = function_exists('app')
            ? app(McpToolRegistry::class)
            : new McpToolRegistry();

        return $this->mcpToolRegistry;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function loadShortTermHistory(): array
    {
        if (! $this->shortTermMemoryEnabled || $this->sessionId === null) {
            return [];
        }

        return app(ShortTermMemory::class)->messages($this->sessionId);
    }

    private function appendToShortTermMemory(string $userInput, string $assistantContent): void
    {
        if (! $this->shortTermMemoryEnabled || $this->sessionId === null) {
            return;
        }

        $memory = app(ShortTermMemory::class);
        $ttl = $this->memoryTtlSeconds;

        $memory->append($this->sessionId, ['role' => 'user', 'content' => $userInput], $ttl);
        $memory->append($this->sessionId, ['role' => 'assistant', 'content' => $assistantContent], $ttl);
    }
}
