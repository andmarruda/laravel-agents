<?php

namespace Andmarruda\LaravelAgents\Observability\Exporters;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceExporter;
use Andmarruda\LaravelAgents\Observability\Data\Span;
use Andmarruda\LaravelAgents\Observability\Data\Trace;

class OpenTelemetryTraceExporter implements TraceExporter
{
    /**
     * @var callable(array<string, mixed>): void|null
     */
    protected $sink;

    /**
     * @param callable(array<string, mixed>): void|null $sink
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink;
    }

    public function export(Trace $trace): void
    {
        if (! $this->sink) {
            return;
        }

        ($this->sink)($this->toPayload($trace));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(Trace $trace): array
    {
        return [
            'resourceSpans' => [[
                'scopeSpans' => [[
                    'spans' => array_map(fn (Span $span) => [
                        'traceId' => $trace->id,
                        'spanId' => $span->id,
                        'parentSpanId' => $span->parentId,
                        'name' => $span->name,
                        'kind' => $span->kind,
                        'startTimeUnixNano' => $this->nanos($span->startedAt->format('U.u')),
                        'endTimeUnixNano' => $span->endedAt ? $this->nanos($span->endedAt->format('U.u')) : null,
                        'status' => [
                            'code' => strtoupper($span->status->value),
                            'message' => $span->statusMessage,
                        ],
                        'attributes' => $this->attributes($span->attributes),
                    ], $trace->spans),
                ]],
            ]],
        ];
    }

    protected function nanos(string $timestamp): string
    {
        return (string) ((int) round(((float) $timestamp) * 1_000_000_000));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<int, array<string, mixed>>
     */
    protected function attributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[] = [
                'key' => $key,
                'value' => ['stringValue' => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)],
            ];
        }

        return $normalized;
    }
}
