<?php

namespace Andmarruda\LaravelAgents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Andmarruda\LaravelAgents\Ports\ModelPort model(string $model)
 * @method static \Andmarruda\LaravelAgents\Agents\Agent agent(string|\Andmarruda\LaravelAgents\Agents\Agent $agent)
 */
class LaravelAgents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Andmarruda\LaravelAgents\LaravelAgentsManager::class;
    }
}
