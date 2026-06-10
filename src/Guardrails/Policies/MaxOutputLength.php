<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class MaxOutputLength implements OutputGuardrail
{
    public function __construct(protected int $maximum, protected bool $retry = true)
    {
    }

    public function evaluate(mixed $output, GuardrailContext $context): GuardrailResult
    {
        $length = is_string($output) ? strlen($output) : strlen(json_encode($output) ?: '');
        if ($length <= $this->maximum) {
            return GuardrailResult::allow($output);
        }

        $violation = new Violation('max_output_length', 'Output exceeds the maximum allowed length.', metadata: [
            'maximum' => $this->maximum,
            'actual' => $length,
        ]);

        return $this->retry ? GuardrailResult::retry($violation) : GuardrailResult::deny($violation);
    }
}
