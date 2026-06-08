<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Andmarruda\LaravelAgents\Data\AgentResponse;

class AgentRunFinished
{
    public function __construct(
        public readonly string $agent,
        public readonly AgentResponse $response,
    ) {
    }
}
