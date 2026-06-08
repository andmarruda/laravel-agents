<?php

namespace Andmarruda\LaravelAgents\Observability\Contracts;

use Andmarruda\LaravelAgents\Observability\Data\Trace;

interface TraceExporter
{
    public function export(Trace $trace): void;
}
