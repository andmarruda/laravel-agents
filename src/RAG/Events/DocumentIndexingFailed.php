<?php

namespace Andmarruda\LaravelAgents\RAG\Events;

use Throwable;

class DocumentIndexingFailed
{
    public function __construct(
        public readonly Throwable $exception,
        public readonly ?string $namespace = null,
    ) {
    }
}
