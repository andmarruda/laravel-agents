<?php

namespace Andmarruda\LaravelAgents\Observability\Http;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Illuminate\Http\JsonResponse;

class TraceDashboardController
{
    public function __construct(
        protected TraceStore $traces,
    ) {
    }

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                fn ($trace) => $trace->toArray(),
                $this->traces->recent((int) request()->query('limit', 50)),
            ),
        ]);
    }

    public function show(string $traceId): JsonResponse
    {
        $trace = $this->traces->get($traceId);

        if (! $trace) {
            return new JsonResponse(['message' => 'Trace not found.'], 404);
        }

        return new JsonResponse(['data' => $trace->toArray()]);
    }
}
