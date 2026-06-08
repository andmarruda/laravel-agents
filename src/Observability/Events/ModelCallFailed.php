<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Throwable;

class ModelCallFailed
{
    public function __construct(
        public readonly ?string $model,
        public readonly Throwable $exception,
    ) {
    }
}
