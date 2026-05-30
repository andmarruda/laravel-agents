<?php

namespace Andmarruda\LaravelAgents;

use Andmarruda\LaravelAgents\Agents\Agent;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class LaravelAgentsManager
{
    public function __construct(
        protected ModelRouter $models,
    ) {
    }

    public function model(string $model): ModelPort
    {
        return $this->models->for($model);
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
