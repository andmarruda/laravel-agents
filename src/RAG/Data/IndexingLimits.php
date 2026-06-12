<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

use InvalidArgumentException;
use RuntimeException;

class IndexingLimits
{
    public function __construct(
        public readonly int $maxDocumentBytes = 10_485_760,
        public readonly int $maxExtractedTextBytes = 10_485_760,
        public readonly int $maxChunksPerDocument = 10_000,
        public readonly int $maxChunkBytes = 100_000,
    ) {
        if ($maxDocumentBytes < 1 || $maxExtractedTextBytes < 1 || $maxChunksPerDocument < 1 || $maxChunkBytes < 1) {
            throw new InvalidArgumentException('RAG indexing limits must be at least 1.');
        }
    }

    public function validateDocument(Document $document): void
    {
        $bytes = strlen($document->content);

        if ($bytes > $this->maxDocumentBytes) {
            throw new RuntimeException("RAG document [{$document->id}] exceeds the {$this->maxDocumentBytes} byte limit.");
        }

        if ($bytes > $this->maxExtractedTextBytes) {
            throw new RuntimeException("RAG document [{$document->id}] exceeds the {$this->maxExtractedTextBytes} extracted text byte limit.");
        }
    }

    /**
     * @param array<int, DocumentChunk> $chunks
     */
    public function validateChunks(Document $document, array $chunks): void
    {
        if (count($chunks) > $this->maxChunksPerDocument) {
            throw new RuntimeException("RAG document [{$document->id}] exceeds the {$this->maxChunksPerDocument} chunk limit.");
        }

        foreach ($chunks as $chunk) {
            if (strlen($chunk->content) > $this->maxChunkBytes) {
                throw new RuntimeException("RAG chunk [{$chunk->id}] exceeds the {$this->maxChunkBytes} byte limit.");
            }
        }
    }
}
