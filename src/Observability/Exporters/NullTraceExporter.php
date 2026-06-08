<?php

namespace Andmarruda\LaravelAgents\Observability\Exporters;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceExporter;
use Andmarruda\LaravelAgents\Observability\Data\Trace;

class NullTraceExporter implements TraceExporter
{
    public function export(Trace $trace): void
    {
    }
}
