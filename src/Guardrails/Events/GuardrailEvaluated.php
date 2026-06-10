<?php

namespace Andmarruda\LaravelAgents\Guardrails\Events;

use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;

class GuardrailEvaluated
{
    public function __construct(
        public readonly string $policy,
        public readonly GuardrailContext $context,
        public readonly GuardrailResult $result,
        public readonly float $latencyMs,
    ) {
    }
}
