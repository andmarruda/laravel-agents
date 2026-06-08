<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class ToolCallFinished
{
    public function __construct(
        public readonly string $tool,
        public readonly mixed $result,
    ) {
    }
}
