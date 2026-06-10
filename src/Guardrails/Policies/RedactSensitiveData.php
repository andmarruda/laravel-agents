<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class RedactSensitiveData implements InputGuardrail, OutputGuardrail
{
    /**
     * @param array<string, string> $patterns
     */
    public function __construct(protected array $patterns = [])
    {
        $this->patterns = $patterns ?: [
            'email' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            'credit_card' => '/\b(?:\d[ -]*?){13,19}\b/',
            'api_key' => '/\b(?:sk|pk|api)[-_][A-Za-z0-9_-]{16,}\b/i',
        ];
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        $redacted = $this->redact($value);

        return $redacted === $value
            ? GuardrailResult::allow($value)
            : GuardrailResult::modify($redacted, [new Violation('sensitive_data_redacted', 'Sensitive data was redacted.')]);
    }

    protected function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            foreach ($this->patterns as $name => $pattern) {
                $value = preg_replace($pattern, '[REDACTED:'.strtoupper($name).']', $value) ?? $value;
            }

            return $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->redact($item), $value);
        }

        return $value;
    }
}
