<?php

namespace Andmarruda\LaravelAgents\Guardrails\Stores;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ApprovalStore;
use Andmarruda\LaravelAgents\Guardrails\Enums\ApprovalStatus;
use Andmarruda\LaravelAgents\Guardrails\Events\ApprovalStatusChanged;
use Andmarruda\LaravelAgents\Observability\Support\LaravelEventDispatcher;
use RuntimeException;

class InMemoryApprovalStore implements ApprovalStore
{
    /**
     * @var array<string, ApprovalRequest>
     */
    protected array $requests = [];

    public function __construct(protected ?LaravelEventDispatcher $events = null)
    {
    }

    public function put(ApprovalRequest $request): void
    {
        if (isset($this->requests[$request->id])) {
            throw new RuntimeException("Approval [{$request->id}] already exists.");
        }

        $this->requests[$request->id] = $request;
    }

    public function get(string $id): ?ApprovalRequest
    {
        $request = $this->requests[$id] ?? null;

        if ($request?->expired() && $request->status === ApprovalStatus::Pending) {
            return $this->save($request->withStatus(ApprovalStatus::Expired));
        }

        return $request;
    }

    public function approve(string $id): ApprovalRequest
    {
        return $this->transition($id, ApprovalStatus::Approved);
    }

    public function deny(string $id): ApprovalRequest
    {
        return $this->transition($id, ApprovalStatus::Denied);
    }

    public function consume(string $id, mixed $payload): ApprovalRequest
    {
        $request = $this->require($id);

        if ($request->status !== ApprovalStatus::Approved) {
            throw new RuntimeException("Approval [{$id}] is not approved or was already consumed.");
        }

        if (! hash_equals($request->fingerprint, ApprovalRequest::fingerprint($payload))) {
            throw new RuntimeException("Approval [{$id}] payload was mutated.");
        }

        return $this->save($request->withStatus(ApprovalStatus::Consumed));
    }

    protected function transition(string $id, ApprovalStatus $status): ApprovalRequest
    {
        $request = $this->require($id);

        if ($request->status !== ApprovalStatus::Pending) {
            throw new RuntimeException("Approval [{$id}] is no longer pending.");
        }

        return $this->save($request->withStatus($status));
    }

    protected function require(string $id): ApprovalRequest
    {
        return $this->get($id) ?? throw new RuntimeException("Approval [{$id}] was not found.");
    }

    protected function save(ApprovalRequest $request): ApprovalRequest
    {
        $this->requests[$request->id] = $request;
        $this->events?->dispatch(new ApprovalStatusChanged($request));

        return $request;
    }
}
