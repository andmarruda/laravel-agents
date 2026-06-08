<?php

namespace Andmarruda\LaravelAgents\Observability\Support;

use Throwable;

class LaravelEventDispatcher
{
    public function dispatch(object $event): void
    {
        if (! function_exists('app')) {
            return;
        }

        try {
            app('events')->dispatch($event);
        } catch (Throwable) {
        }
    }
}
