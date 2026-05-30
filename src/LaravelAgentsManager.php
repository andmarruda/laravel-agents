<?php

namespace Andmarruda\LaravelAgents;

use Andmarruda\LaravelAgents\Agents\Agent;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Ports\ImageGenerationPort;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class LaravelAgentsManager
{
    public function __construct(
        protected ModelRouter $models,
        protected ImageRouter $images,
        protected AgentKernel $kernel,
    ) {
    }

    public function kernel(): AgentKernel
    {
        return $this->kernel;
    }

    public function model(string $model): ModelPort
    {
        return $this->models->for($model);
    }

    public function image(?string $model = null): ImageGenerationPort
    {
        return $this->images->for($model);
    }

    /**
     * @param class-string<Agent>|Agent $agent
     */
    public function agent(string|Agent $agent): Agent
    {
        $instance = is_string($agent) ? app($agent) : $agent;

        $instance->setModelRouter($this->models);
        $instance->bootAgent();

        return $instance;
    }
}
