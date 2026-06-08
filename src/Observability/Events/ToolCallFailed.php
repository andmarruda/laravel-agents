<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Throwable;

class ToolCallFailed
{
    public function __construct(
        public readonly string $tool,
        public readonly Throwable $exception,
    ) {
    }
}
