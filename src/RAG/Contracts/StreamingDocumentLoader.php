<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

use Andmarruda\LaravelAgents\RAG\Data\Document;

interface StreamingDocumentLoader extends DocumentLoader
{
    /**
     * @return iterable<int, Document>
     */
    public function documents(): iterable;
}
