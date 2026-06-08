<?php

namespace Andmarruda\LaravelAgents\Observability\Stores;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Andmarruda\LaravelAgents\Observability\Data\Trace;

class InMemoryTraceStore implements TraceStore
{
    /**
     * @var array<string, Trace>
     */
    protected array $traces = [];

    public function put(Trace $trace): void
    {
        $this->traces[$trace->id] = $trace;
    }

    public function get(string $traceId): ?Trace
    {
        return $this->traces[$traceId] ?? null;
    }

    public function recent(int $limit = 50): array
    {
        return array_slice(array_reverse(array_values($this->traces)), 0, $limit);
    }
}
