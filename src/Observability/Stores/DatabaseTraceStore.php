<?php

namespace Andmarruda\LaravelAgents\Observability\Stores;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Andmarruda\LaravelAgents\Observability\Data\Span;
use Andmarruda\LaravelAgents\Observability\Data\Trace;
use Andmarruda\LaravelAgents\Observability\Data\TraceStatus;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

class DatabaseTraceStore implements TraceStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $traceTable = 'agent_traces',
        protected string $spanTable = 'agent_spans',
    ) {
    }

    public function put(Trace $trace): void
    {
        $this->connection->table($this->traceTable)->updateOrInsert(
            ['id' => $trace->id],
            [
                'name' => $trace->name,
                'status' => $trace->status->value,
                'status_message' => $trace->statusMessage,
                'started_at' => $trace->startedAt,
                'ended_at' => $trace->endedAt,
                'duration_ms' => $trace->durationMs(),
                'attributes' => json_encode($trace->attributes, JSON_UNESCAPED_SLASHES),
                'created_at' => new DateTimeImmutable(),
                'updated_at' => new DateTimeImmutable(),
            ],
        );

        foreach ($trace->spans as $span) {
            $this->connection->table($this->spanTable)->updateOrInsert(
                ['id' => $span->id],
                [
                    'trace_id' => $span->traceId,
                    'parent_id' => $span->parentId,
                    'name' => $span->name,
                    'kind' => $span->kind,
                    'status' => $span->status->value,
                    'status_message' => $span->statusMessage,
                    'started_at' => $span->startedAt,
                    'ended_at' => $span->endedAt,
                    'duration_ms' => $span->durationMs(),
                    'attributes' => json_encode($span->attributes, JSON_UNESCAPED_SLASHES),
                    'created_at' => new DateTimeImmutable(),
                    'updated_at' => new DateTimeImmutable(),
                ],
            );
        }
    }

    public function get(string $traceId): ?Trace
    {
        $row = $this->connection->table($this->traceTable)->where('id', $traceId)->first();

        if (! $row) {
            return null;
        }

        $trace = $this->traceFromRow($row);
        $spans = $this->connection->table($this->spanTable)
            ->where('trace_id', $traceId)
            ->orderBy('started_at')
            ->get();

        foreach ($spans as $spanRow) {
            $trace->addSpan($this->spanFromRow($spanRow));
        }

        return $trace;
    }

    public function recent(int $limit = 50): array
    {
        return $this->connection->table($this->traceTable)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->traceFromRow($row))
            ->all();
    }

    protected function traceFromRow(object $row): Trace
    {
        $trace = new Trace(
            id: (string) $row->id,
            name: (string) $row->name,
            startedAt: new DateTimeImmutable((string) $row->started_at),
            attributes: json_decode((string) $row->attributes, true) ?: [],
        );
        $trace->endedAt = $row->ended_at ? new DateTimeImmutable((string) $row->ended_at) : null;
        $trace->status = TraceStatus::from((string) $row->status);
        $trace->statusMessage = $row->status_message;

        return $trace;
    }

    protected function spanFromRow(object $row): Span
    {
        $span = new Span(
            id: (string) $row->id,
            traceId: (string) $row->trace_id,
            parentId: $row->parent_id ? (string) $row->parent_id : null,
            name: (string) $row->name,
            kind: (string) $row->kind,
            startedAt: new DateTimeImmutable((string) $row->started_at),
            attributes: json_decode((string) $row->attributes, true) ?: [],
        );
        $span->endedAt = $row->ended_at ? new DateTimeImmutable((string) $row->ended_at) : null;
        $span->status = TraceStatus::from((string) $row->status);
        $span->statusMessage = $row->status_message;

        return $span;
    }
}
