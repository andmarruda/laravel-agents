<?php

namespace Andmarruda\LaravelAgents\Guardrails\Events;

use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class GuardrailRetrying
{
    /**
     * @param array<int, Violation> $violations
     */
    public function __construct(
        public readonly int $attempt,
        public readonly array $violations,
    ) {
    }
}
