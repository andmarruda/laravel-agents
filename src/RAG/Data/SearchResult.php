<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

class SearchResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly float $score,
        public readonly array $metadata = [],
        public readonly ?string $documentId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'content' => $this->content,
            'score' => $this->score,
            'metadata' => $this->metadata,
        ];
    }
}
