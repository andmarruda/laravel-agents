<?php

namespace Andmarruda\LaravelAgents\Guardrails\Contracts;

use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;

interface OutputGuardrail
{
    public function evaluate(mixed $output, GuardrailContext $context): GuardrailResult;
}
