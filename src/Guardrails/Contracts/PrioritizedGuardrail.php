<?php

namespace Andmarruda\LaravelAgents\Guardrails\Contracts;

interface PrioritizedGuardrail
{
    public function priority(): int;
}
