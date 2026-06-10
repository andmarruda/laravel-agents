<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Closure;
use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;

class ContextPolicy implements InputGuardrail, OutputGuardrail, ToolGuardrail
{
    public function __construct(protected Closure $policy)
    {
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        return ($this->policy)($value, $context);
    }
}
