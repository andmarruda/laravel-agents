<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

use Andmarruda\LaravelAgents\Observability\Data\Trace;

class TraceFinished
{
    public function __construct(
        public readonly Trace $trace,
    ) {
    }
}
