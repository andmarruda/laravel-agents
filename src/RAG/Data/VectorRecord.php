<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

class VectorRecord
{
    /**
     * @param array<int, float|int> $vector
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $vector,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly ?string $documentId = null,
    ) {
    }
}
