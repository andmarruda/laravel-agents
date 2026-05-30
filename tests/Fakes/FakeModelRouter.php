<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Ports\ModelPort;
use Andmarruda\LaravelAgents\Models\ModelRouter;

class FakeModelRouter extends ModelRouter
{
    /**
     * @param array<string, ModelPort> $models
     */
    public function __construct(
        protected array $models,
    ) {
        parent::__construct([]);
    }

    public function for(?string $model = null): ModelPort
    {
        return $this->models[$model] ?? $this->models['default'];
    }
}
