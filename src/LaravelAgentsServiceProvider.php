<?php

namespace Andmarruda\LaravelAgents;

use Illuminate\Support\ServiceProvider;
use Andmarruda\LaravelAgents\Models\ModelRouter;

class LaravelAgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agents.php', 'agents');

        $this->app->singleton(ModelRouter::class, function ($app) {
            return new ModelRouter($app['config']->get('agents', []));
        });

        $this->app->singleton(LaravelAgentsManager::class, function ($app) {
            return new LaravelAgentsManager($app->make(ModelRouter::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agents.php' => config_path('agents.php'),
        ], 'agents-config');
    }
}
