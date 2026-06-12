<?php

namespace Andmarruda\LaravelAgents\RAG\Events;

class DocumentIndexingProgressed
{
    public function __construct(
        public readonly int $batch,
        public readonly int $batches,
        public readonly int $documents,
        public readonly ?string $namespace = null,
    ) {
    }
}
