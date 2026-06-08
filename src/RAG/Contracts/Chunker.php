<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\DocumentChunk;

interface Chunker
{
    /**
     * @return array<int, DocumentChunk>
     */
    public function chunk(Document $document): array;
}
