<?php

namespace Andmarruda\LaravelAgents\RAG\Events;

class DocumentIndexingCompleted
{
    /**
     * @param array<string, mixed> $summary
     */
    public function __construct(public readonly array $summary)
    {
    }
}
