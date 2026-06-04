<?php

namespace Andmarruda\LaravelAgents;

use Andmarruda\LaravelAgents\Contracts\Memory\LongTermMemory;
use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\Memory\DatabaseLongTermAdapter;
use Andmarruda\LaravelAgents\Memory\RedisShortTermAdapter;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Illuminate\Support\ServiceProvider;

class LaravelAgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agents.php', 'agents');

        $this->app->singleton(ModelRouter::class, function ($app) {
            return new ModelRouter($app['config']->get('agents', []));
        });

        $this->app->singleton(ImageRouter::class, function ($app) {
            return new ImageRouter($app['config']->get('agents', []));
        });

        $this->app->singleton(AgentKernel::class, function ($app) {
            return new AgentKernel(
                $app->make(ModelRouter::class),
                $app->make(ImageRouter::class),
            );
        });

        $this->app->singleton(LaravelAgentsManager::class, function ($app) {
            return new LaravelAgentsManager(
                $app->make(ModelRouter::class),
                $app->make(ImageRouter::class),
                $app->make(AgentKernel::class),
            );
        });

        $this->app->singleton(ShortTermMemory::class, function ($app) {
            $connection = $app['redis']->connection(
                $app['config']->get('agents.memory.short_term.connection', 'default')
            );

            return new RedisShortTermAdapter(
                $connection,
                $app['config']->get('agents.memory.short_term.prefix', 'agents:session:'),
            );
        });

        $this->app->singleton(LongTermMemory::class, function ($app) {
            return new DatabaseLongTermAdapter(
                $app['db']->connection(
                    $app['config']->get('agents.memory.long_term.connection')
                ),
                $app['config']->get('agents.memory.long_term.table', 'agent_memories'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agents.php' => config_path('agents.php'),
        ], 'agents-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_agent_memories_table.php' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_agent_memories_table.php'
            ),
        ], 'agents-migrations');
    }
}
