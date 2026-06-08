<?php

namespace Andmarruda\LaravelAgents\Observability\Data;

use DateTimeImmutable;
use Throwable;

class Trace
{
    public ?DateTimeImmutable $endedAt = null;

    public TraceStatus $status = TraceStatus::Running;

    public ?string $statusMessage = null;

    /**
     * @var array<int, Span>
     */
    public array $spans = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly DateTimeImmutable $startedAt,
        public array $attributes = [],
    ) {
    }

    public function addSpan(Span $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function finish(array $attributes = []): void
    {
        $this->endedAt = new DateTimeImmutable();
        $this->status = TraceStatus::Ok;
        $this->attributes = [...$this->attributes, ...$attributes];
    }

    public function fail(Throwable $throwable): void
    {
        $this->endedAt = new DateTimeImmutable();
        $this->status = TraceStatus::Error;
        $this->statusMessage = $throwable->getMessage();
        $this->attributes = [
            ...$this->attributes,
            'exception.class' => $throwable::class,
            'exception.message' => $throwable->getMessage(),
        ];
    }

    public function durationMs(): ?float
    {
        if (! $this->endedAt) {
            return null;
        }

        return ((float) $this->endedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_message' => $this->statusMessage,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'ended_at' => $this->endedAt?->format(DATE_ATOM),
            'duration_ms' => $this->durationMs(),
            'attributes' => $this->attributes,
            'spans' => array_map(fn (Span $span) => $span->toArray(), $this->spans),
        ];
    }
}
