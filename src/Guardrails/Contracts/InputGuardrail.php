<?php

namespace Andmarruda\LaravelAgents\Guardrails\Contracts;

use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;

interface InputGuardrail
{
    public function evaluate(mixed $input, GuardrailContext $context): GuardrailResult;
}
