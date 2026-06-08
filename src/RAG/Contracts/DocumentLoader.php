<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

use Andmarruda\LaravelAgents\RAG\Data\Document;

interface DocumentLoader
{
    /**
     * @return array<int, Document>
     */
    public function load(): array;
}
