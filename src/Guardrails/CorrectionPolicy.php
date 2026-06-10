<?php

namespace Andmarruda\LaravelAgents\Guardrails;

use Andmarruda\LaravelAgents\Guardrails\Data\Violation;
use InvalidArgumentException;

class CorrectionPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 2,
        public readonly int $backoffMilliseconds = 0,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Correction attempts must be at least 1.');
        }

        if ($backoffMilliseconds < 0) {
            throw new InvalidArgumentException('Correction backoff must not be negative.');
        }
    }

    /**
     * @param array<int, Violation> $violations
     */
    public function prompt(array $violations): string
    {
        $errors = array_map(
            fn (Violation $violation) => array_filter([
                'code' => $violation->code,
                'path' => $violation->path,
                'message' => $violation->message,
            ]),
            $violations,
        );

        return 'Correct the previous response and return only the corrected response. Validation errors: '.
            json_encode($errors, JSON_UNESCAPED_SLASHES);
    }

    public function backoff(): void
    {
        if ($this->backoffMilliseconds > 0) {
            usleep($this->backoffMilliseconds * 1000);
        }
    }
}
