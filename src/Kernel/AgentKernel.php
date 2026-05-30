<?php

namespace Andmarruda\LaravelAgents\Kernel;

use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use Andmarruda\LaravelAgents\Ports\ImageGenerationPort;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class AgentKernel
{
    public function __construct(
        protected ModelRouter $models,
        protected ImageRouter $images,
    ) {
    }

    public function text(?string $model = null): ModelPort
    {
        return $this->models->for($model);
    }

    public function image(?string $model = null): ImageGenerationPort
    {
        return $this->images->for($model);
    }
}
