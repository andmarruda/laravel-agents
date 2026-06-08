<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Andmarruda\LaravelAgents\Data\ModelResponse;

class ModelCallFinished
{
    public function __construct(
        public readonly ModelResponse $response,
    ) {
    }
}
