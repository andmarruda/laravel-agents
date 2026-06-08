<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Throwable;

class AgentRunFailed
{
    public function __construct(
        public readonly string $agent,
        public readonly Throwable $exception,
    ) {
    }
}
