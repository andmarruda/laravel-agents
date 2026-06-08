<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

class DocumentChunk
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $documentId,
        public readonly string $content,
        public readonly int $index,
        public readonly array $metadata = [],
    ) {
    }
}
