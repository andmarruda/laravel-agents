<?php

namespace Andmarruda\LaravelAgents\Guardrails\Approvals;

use Andmarruda\LaravelAgents\Guardrails\Enums\ApprovalStatus;
use DateTimeImmutable;

class ApprovalRequest
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly string $fingerprint,
        public readonly ApprovalStatus $status = ApprovalStatus::Pending,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly array $metadata = [],
        public readonly ?DateTimeImmutable $decidedAt = null,
    ) {
    }

    public static function create(string $action, mixed $payload, ?DateTimeImmutable $expiresAt = null, array $metadata = []): static
    {
        return new static(
            bin2hex(random_bytes(16)),
            $action,
            self::fingerprint($payload),
            ApprovalStatus::Pending,
            $expiresAt,
            $metadata,
        );
    }

    public static function fingerprint(mixed $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null');
    }

    public function expired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= new DateTimeImmutable();
    }

    public function withStatus(ApprovalStatus $status): static
    {
        return new static(
            $this->id,
            $this->action,
            $this->fingerprint,
            $status,
            $this->expiresAt,
            $this->metadata,
            new DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'fingerprint' => $this->fingerprint,
            'status' => $this->status->value,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'metadata' => $this->metadata,
            'decided_at' => $this->decidedAt?->format(DATE_ATOM),
        ];
    }
}
