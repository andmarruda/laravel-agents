<?php

namespace Andmarruda\LaravelAgents\Guardrails\Enums;

enum GuardrailDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Modify = 'modify';
    case Retry = 'retry';
    case RequireApproval = 'require_approval';
}
