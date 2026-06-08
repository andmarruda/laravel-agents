<?php

namespace Andmarruda\LaravelAgents\RAG;

use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;
use InvalidArgumentException;
use RuntimeException;

class RagIndexer
{
    public function __construct(
        protected Chunker $chunker,
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected int $embeddingBatchSize = 100,
    ) {
        if ($embeddingBatchSize < 1) {
            throw new InvalidArgumentException('RAG indexing batch size must be at least 1.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function index(DocumentLoader $loader, ?string $namespace = null): array
    {
        return $this->indexDocuments($loader->load(), $namespace);
    }

    /**
     * @param array<int, Document> $documents
     * @return array<string, mixed>
     */
    public function indexDocuments(array $documents, ?string $namespace = null): array
    {
        $indexed = 0;
        foreach ($documents as $document) {
            if (! $document instanceof Document) {
                throw new InvalidArgumentException('RAG index documents must be Document instances.');
            }

            $chunks = $this->chunker->chunk($document);
            $records = [];

            foreach (array_chunk($chunks, $this->embeddingBatchSize) as $batch) {
                $vectors = $this->embeddings->embed(array_map(fn ($chunk) => $chunk->content, $batch));

                if (count($vectors) !== count($batch)) {
                    throw new RuntimeException('Embedding provider returned a different number of vectors than chunks.');
                }

                foreach ($batch as $index => $chunk) {
                    $records[] = new VectorRecord(
                        id: $chunk->id,
                        vector: $vectors[$index],
                        content: $chunk->content,
                        metadata: $chunk->metadata,
                        documentId: $chunk->documentId,
                    );
                }
            }

            $this->store->replaceDocument($document->id, $records, $namespace);
            $indexed += count($records);
        }

        return [
            'documents' => count($documents),
            'chunks' => $indexed,
            'namespace' => $namespace,
        ];
    }

    public function deleteDocument(string $documentId, ?string $namespace = null): void
    {
        $this->store->deleteByDocument($documentId, $namespace);
    }
}
