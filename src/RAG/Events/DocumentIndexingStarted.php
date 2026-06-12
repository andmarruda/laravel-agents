<?php

namespace Andmarruda\LaravelAgents\RAG\Events;

class DocumentIndexingStarted
{
    public function __construct(
        public readonly int $documents,
        public readonly ?string $namespace = null,
    ) {
    }
}
