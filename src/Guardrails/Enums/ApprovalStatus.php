<?php

namespace Andmarruda\LaravelAgents\Guardrails\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Consumed = 'consumed';
}
