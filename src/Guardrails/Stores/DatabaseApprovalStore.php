<?php

namespace Andmarruda\LaravelAgents\Guardrails\Stores;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ApprovalStore;
use Andmarruda\LaravelAgents\Guardrails\Enums\ApprovalStatus;
use Andmarruda\LaravelAgents\Guardrails\Events\ApprovalStatusChanged;
use Andmarruda\LaravelAgents\Observability\Support\LaravelEventDispatcher;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

class DatabaseApprovalStore implements ApprovalStore
{
    public function __construct(
        protected ConnectionInterface $database,
        protected string $table = 'agent_approvals',
        protected ?LaravelEventDispatcher $events = null,
    ) {
    }

    public function put(ApprovalRequest $request): void
    {
        $this->database->table($this->table)->insert($this->row($request, true));
    }

    public function get(string $id): ?ApprovalRequest
    {
        $row = $this->database->table($this->table)->where('id', $id)->first();
        if (! $row) {
            return null;
        }

        $request = $this->hydrate((array) $row);
        if ($request->expired() && $request->status === ApprovalStatus::Pending) {
            return $this->transition($request, ApprovalStatus::Expired);
        }

        return $request;
    }

    public function approve(string $id): ApprovalRequest
    {
        return $this->transition($this->pending($id), ApprovalStatus::Approved);
    }

    public function deny(string $id): ApprovalRequest
    {
        return $this->transition($this->pending($id), ApprovalStatus::Denied);
    }

    public function consume(string $id, mixed $payload): ApprovalRequest
    {
        $request = $this->get($id) ?? throw new RuntimeException("Approval [{$id}] was not found.");
        if ($request->status !== ApprovalStatus::Approved) {
            throw new RuntimeException("Approval [{$id}] is not approved or was already consumed.");
        }
        if (! hash_equals($request->fingerprint, ApprovalRequest::fingerprint($payload))) {
            throw new RuntimeException("Approval [{$id}] payload was mutated.");
        }

        return $this->transition($request, ApprovalStatus::Consumed);
    }

    protected function pending(string $id): ApprovalRequest
    {
        $request = $this->get($id) ?? throw new RuntimeException("Approval [{$id}] was not found.");

        return $request->status === ApprovalStatus::Pending
            ? $request
            : throw new RuntimeException("Approval [{$id}] is no longer pending.");
    }

    protected function transition(ApprovalRequest $request, ApprovalStatus $status): ApprovalRequest
    {
        $updated = $request->withStatus($status);
        $affected = $this->database->table($this->table)
            ->where('id', $request->id)
            ->where('status', $request->status->value)
            ->update($this->row($updated));

        if ($affected !== 1) {
            throw new RuntimeException("Approval [{$request->id}] changed concurrently.");
        }

        $this->events?->dispatch(new ApprovalStatusChanged($updated));

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(ApprovalRequest $request, bool $creating = false): array
    {
        $row = [
            'id' => $request->id,
            'action' => $request->action,
            'fingerprint' => $request->fingerprint,
            'status' => $request->status->value,
            'expires_at' => $request->expiresAt?->format('Y-m-d H:i:s'),
            'metadata' => json_encode($request->metadata, JSON_UNESCAPED_SLASHES),
            'decided_at' => $request->decidedAt?->format('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($creating) {
            $row['created_at'] = date('Y-m-d H:i:s');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function hydrate(array $row): ApprovalRequest
    {
        return new ApprovalRequest(
            (string) $row['id'],
            (string) $row['action'],
            (string) $row['fingerprint'],
            ApprovalStatus::from((string) $row['status']),
            isset($row['expires_at']) ? new DateTimeImmutable((string) $row['expires_at']) : null,
            json_decode((string) ($row['metadata'] ?? '{}'), true) ?: [],
            isset($row['decided_at']) ? new DateTimeImmutable((string) $row['decided_at']) : null,
        );
    }
}
