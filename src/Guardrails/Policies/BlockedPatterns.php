<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class BlockedPatterns implements InputGuardrail, OutputGuardrail
{
    /**
     * @param array<int, string> $patterns
     */
    public function __construct(protected array $patterns, protected bool $regularExpressions = false)
    {
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        $text = is_string($value) ? $value : (json_encode($value) ?: '');

        foreach ($this->patterns as $pattern) {
            $matched = $this->regularExpressions
                ? preg_match($pattern, $text) === 1
                : str_contains(strtolower($text), strtolower($pattern));

            if ($matched) {
                return GuardrailResult::deny(new Violation('blocked_pattern', 'Content matched a blocked pattern.'));
            }
        }

        return GuardrailResult::allow($value);
    }
}
