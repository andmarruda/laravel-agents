<?php

namespace Andmarruda\LaravelAgents\Guardrails\Exceptions;

use Andmarruda\LaravelAgents\Guardrails\Data\Violation;
use RuntimeException;

class GuardrailDeniedException extends RuntimeException
{
    /**
     * @param array<int, Violation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct($violations[0]->message ?? 'Operation denied by a guardrail.');
    }
}
