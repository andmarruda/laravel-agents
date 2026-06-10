<?php

namespace Andmarruda\LaravelAgents\Guardrails\Events;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;

class ApprovalStatusChanged
{
    public function __construct(public readonly ApprovalRequest $approval)
    {
    }
}
