<?php

namespace Andmarruda\LaravelAgents\Observability\Data;

enum TraceStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Error = 'error';
}
