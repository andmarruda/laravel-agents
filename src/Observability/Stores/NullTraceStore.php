<?php

namespace Andmarruda\LaravelAgents\Observability\Stores;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Andmarruda\LaravelAgents\Observability\Data\Trace;

class NullTraceStore implements TraceStore
{
    public function put(Trace $trace): void
    {
    }

    public function get(string $traceId): ?Trace
    {
        return null;
    }

    public function recent(int $limit = 50): array
    {
        return [];
    }
}
