<?php

namespace Andmarruda\LaravelAgents\Agents;

use RuntimeException;
use Andmarruda\LaravelAgents\Data\AgentResponse;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunFailed;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunFinished;
use Andmarruda\LaravelAgents\Observability\Events\AgentRunStarted;
use Throwable;

abstract class SupervisorAgent extends Agent
{
    /**
     * The agents that the supervisor can delegate tasks to, keyed by their name.
     * 
     * @var array<string, Agent>
     */
    protected array $agents = [];

    /**
     * The maximum number of steps the supervisor will take before finalizing an answer.
     * 
     * @var int
     */
    protected int $maxSteps = 12;

    /**
     * Generates a response for the given input by iteratively deciding whether to delegate to an agent or finalize an answer.
     * 
     * @param string $input The initial task or question to be addressed by the supervisor and its agents.
     * @param array<string, mixed> $context Additional context that can be used in decision making and passed to agents.
     * @return AgentResponse
     */
    public function generate(string $input, array $context = []): AgentResponse
    {
        $this->bootAgent();
        $traceManager = $this->traceManager();
        $trace = $traceManager?->startTrace('agent.supervisor.run', [
            'agent.name' => $this->name(),
            'agent.class' => static::class,
            'input.length' => strlen($input),
        ]);
        $traceManager?->dispatch(new AgentRunStarted($this->name(), $input, $context));
        $steps = [];
        $task = $input;

        try {
            for ($step = 1; $step <= $this->maxSteps; $step++) {
                $decision = $this->decide($task, $steps, $context);
                $action = $decision['action'] ?? null;

                if ($action === 'final') {
                    $agentResponse = new AgentResponse($this->normalizeFinalAnswer($decision['answer'] ?? ''), $steps, [
                        'agent' => $this->name(),
                        'steps' => count($steps),
                        'trace_id' => $trace?->id,
                    ]);
                    $traceManager?->dispatch(new AgentRunFinished($this->name(), $agentResponse));
                    $traceManager?->finishTrace($trace, ['agent.steps' => count($steps)]);

                    return $agentResponse;
                }

                if ($action !== 'delegate') {
                    throw new RuntimeException('Supervisor decision must be either delegate or final.');
                }

                $agentName = $decision['agent'] ?? null;
                $agent = $this->agents[$agentName] ?? null;

                if (! $agent) {
                    throw new RuntimeException("Supervisor selected unknown agent [{$agentName}].");
                }

                $agent->setModelRouter($this->modelRouter());
                $agent->setTraceManager($traceManager);

                $delegateSpan = $traceManager?->startSpan('agent.delegate', 'agent', [
                    'agent.supervisor' => $this->name(),
                    'agent.worker' => $agent->name(),
                    'agent.task' => $decision['task'] ?? $task,
                ]);

                try {
                    $workerResponse = $agent->generate($decision['task'] ?? $task, [
                        ...$context,
                        'supervisor' => $this->name(),
                        'previous_steps' => $steps,
                    ]);
                    $traceManager?->finishSpan($delegateSpan, ['agent.worker_trace_id' => $workerResponse->meta['trace_id'] ?? null]);
                } catch (Throwable $throwable) {
                    $traceManager?->failSpan($delegateSpan, $throwable);

                    throw $throwable;
                }

                $steps[] = [
                    'step' => $step,
                    'agent' => $agent->name(),
                    'task' => $decision['task'] ?? $task,
                    'result' => $workerResponse->content,
                    'meta' => $workerResponse->meta,
                ];

                $task = $decision['next_task'] ?? $task;
            }

            $response = parent::generate($this->finalizePrompt($input, $steps), $context);
            $agentResponse = new AgentResponse($response->content, $steps, [
                ...$response->meta,
                'max_steps_reached' => true,
                'trace_id' => $trace?->id,
            ]);
            $traceManager?->dispatch(new AgentRunFinished($this->name(), $agentResponse));
            $traceManager?->finishTrace($trace, ['agent.steps' => count($steps), 'agent.max_steps_reached' => true]);

            return $agentResponse;
        } catch (Throwable $throwable) {
            $traceManager?->dispatch(new AgentRunFailed($this->name(), $throwable));
            $traceManager?->failTrace($trace, $throwable);

            throw $throwable;
        }
    }

    /**
     * @param array<int, class-string<Agent>|Agent> $agents
     */
    protected function withAgents(array $agents): static
    {
        foreach ($agents as $agent) {
            $instance = is_string($agent) ? app($agent) : $agent;
            $instance->bootAgent();

            $this->agents[$instance->name()] = $instance;
        }

        return $this;
    }

    protected function maxSteps(int $maxSteps): static
    {
        $this->maxSteps = $maxSteps;

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function decide(string $task, array $steps, array $context): array
    {
        $response = $this->generateWithObservability($this->decisionMessages($task, $steps, $context), [
            ...$this->options,
            'temperature' => $this->options['temperature'] ?? 0.1,
        ]);

        $decision = $response->json();

        if (! $decision) {
            throw new RuntimeException('Supervisor returned invalid JSON decision: '.$response->content);
        }

        return $decision;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed> $context
     * @return array<int, array{role: string, content: string}>
     */
    protected function decisionMessages(string $task, array $steps, array $context): array
    {
        $agents = collect($this->agents)
            ->map(fn (Agent $agent) => [
                'name' => $agent->name(),
                'description' => $agent->descriptionText(),
            ])
            ->values()
            ->all();

        return [
            [
                'role' => 'system',
                'content' => trim(($this->instructions ?? '')."\n\n".
                    'You are a supervisor agent. Decide the next action as strict JSON only. '.
                    'Use {"action":"delegate","agent":"agent_name","task":"specific worker task","next_task":"optional updated task"} '.
                    'or {"action":"final","answer":"final answer"}.'),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => $task,
                    'available_agents' => $agents,
                    'previous_steps' => $steps,
                    'context' => $context,
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    protected function finalizePrompt(string $input, array $steps): string
    {
        return 'Create the best final answer for this task using the completed agent steps: '.
            json_encode([
                'task' => $input,
                'steps' => $steps,
            ], JSON_UNESCAPED_SLASHES);
    }

    protected function normalizeFinalAnswer(mixed $answer): string
    {
        if (is_string($answer)) {
            return $answer;
        }

        if (is_scalar($answer) || $answer === null) {
            return (string) $answer;
        }

        return json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
    }
}
