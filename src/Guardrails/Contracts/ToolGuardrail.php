<?php

namespace Andmarruda\LaravelAgents\Guardrails\Contracts;

use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;

interface ToolGuardrail
{
    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult;
}
