<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class BlockedTools implements ToolGuardrail
{
    /**
     * @param array<int, string> $tools
     */
    public function __construct(protected array $tools)
    {
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        return in_array($context->tool, $this->tools, true)
            ? GuardrailResult::deny(new Violation('tool_blocked', 'Tool is blocked.', metadata: ['tool' => $context->tool]))
            : GuardrailResult::allow($value);
    }
}
