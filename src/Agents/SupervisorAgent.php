<?php

namespace Andmarruda\LaravelAgents\Agents;

use RuntimeException;
use Andmarruda\LaravelAgents\Data\AgentResponse;

abstract class SupervisorAgent extends Agent
{
    /**
     * @var array<string, Agent>
     */
    protected array $agents = [];

    protected int $maxSteps = 12;

    public function generate(string $input, array $context = []): AgentResponse
    {
        $this->bootAgent();
        $steps = [];
        $task = $input;

        for ($step = 1; $step <= $this->maxSteps; $step++) {
            $decision = $this->decide($task, $steps, $context);
            $action = $decision['action'] ?? null;

            if ($action === 'final') {
                return new AgentResponse($decision['answer'] ?? '', $steps, [
                    'agent' => $this->name(),
                    'steps' => count($steps),
                ]);
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

            $workerResponse = $agent->generate($decision['task'] ?? $task, [
                ...$context,
                'supervisor' => $this->name(),
                'previous_steps' => $steps,
            ]);

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

        return new AgentResponse($response->content, $steps, [
            ...$response->meta,
            'max_steps_reached' => true,
        ]);
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
        $response = $this->modelRouter()
            ->for($this->model)
            ->generate($this->decisionMessages($task, $steps, $context), [
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
}
