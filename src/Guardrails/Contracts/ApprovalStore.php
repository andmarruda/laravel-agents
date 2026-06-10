<?php

namespace Andmarruda\LaravelAgents\Guardrails\Contracts;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;

interface ApprovalStore
{
    public function put(ApprovalRequest $request): void;

    public function get(string $id): ?ApprovalRequest;

    public function approve(string $id): ApprovalRequest;

    public function deny(string $id): ApprovalRequest;

    public function consume(string $id, mixed $payload): ApprovalRequest;
}
