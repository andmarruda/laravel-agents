<?php

namespace Andmarruda\LaravelAgents\Observability\Contracts;

use Andmarruda\LaravelAgents\Observability\Data\Trace;

interface TraceStore
{
    public function put(Trace $trace): void;

    public function get(string $traceId): ?Trace;

    /**
     * @return array<int, Trace>
     */
    public function recent(int $limit = 50): array;
}
