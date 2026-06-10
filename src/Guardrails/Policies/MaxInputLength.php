<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class MaxInputLength implements InputGuardrail
{
    public function __construct(protected int $maximum)
    {
    }

    public function evaluate(mixed $input, GuardrailContext $context): GuardrailResult
    {
        $length = is_string($input) ? strlen($input) : strlen(json_encode($input) ?: '');

        return $length <= $this->maximum
            ? GuardrailResult::allow($input)
            : GuardrailResult::deny(new Violation('max_input_length', 'Input exceeds the maximum allowed length.', metadata: [
                'maximum' => $this->maximum,
                'actual' => $length,
            ]));
    }
}
