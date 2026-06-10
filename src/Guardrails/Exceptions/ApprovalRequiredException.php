<?php

namespace Andmarruda\LaravelAgents\Guardrails\Exceptions;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;
use RuntimeException;

class ApprovalRequiredException extends RuntimeException
{
    /**
     * @param array<int, Violation> $violations
     */
    public function __construct(
        public readonly ApprovalRequest $approval,
        public readonly array $violations = [],
    ) {
        parent::__construct($violations[0]->message ?? 'Human approval is required.');
    }
}
