<?php

namespace Andmarruda\LaravelAgents\RAG;

use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\MetadataSchema;
use Andmarruda\LaravelAgents\RAG\Contracts\StreamingDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\IndexingLimits;
use Andmarruda\LaravelAgents\RAG\Embeddings\CachedEmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use InvalidArgumentException;
use RuntimeException;

class RagIndexer
{
    public function __construct(
        protected Chunker $chunker,
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected int $embeddingBatchSize = 100,
        protected ?MetadataSchema $metadataSchema = null,
        protected ?IndexingLimits $limits = null,
        protected ?TraceManager $traces = null,
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
        if ($loader instanceof StreamingDocumentLoader) {
            $summary = ['documents' => 0, 'chunks' => 0, 'namespace' => $namespace, 'embedding_cache' => null];
            $batch = [];

            foreach ($loader->documents() as $document) {
                $batch[] = $document;
                if (count($batch) < 25) {
                    continue;
                }

                $summary = $this->mergeSummary($summary, $this->indexDocuments($batch, $namespace));
                $batch = [];
            }

            return $batch === [] ? $summary : $this->mergeSummary($summary, $this->indexDocuments($batch, $namespace));
        }

        return $this->indexDocuments($loader->load(), $namespace);
    }

    /**
     * @param array<int, Document> $documents
     * @return array<string, mixed>
     */
    public function indexDocuments(array $documents, ?string $namespace = null): array
    {
        $cacheBefore = $this->embeddings instanceof CachedEmbeddingProvider ? $this->embeddings->stats() : null;
        $span = $this->traces?->startSpan('rag.index', 'internal', [
            'rag.namespace' => $namespace,
            'rag.documents' => count($documents),
        ]);
        $indexed = 0;
        try {
            foreach ($documents as $document) {
            if (! $document instanceof Document) {
                throw new InvalidArgumentException('RAG index documents must be Document instances.');
            }

            $this->limits?->validateDocument($document);

            if ($this->metadataSchema) {
                $document = new Document(
                    $document->id,
                    $document->content,
                    $this->metadataSchema->validate($document->metadata),
                    $document->source,
                    $document->mimeType,
                    $document->checksum,
                );
            }

            $chunks = $this->chunker->chunk($document);
            $this->limits?->validateChunks($document, $chunks);
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

        $summary = [
            'documents' => count($documents),
            'chunks' => $indexed,
            'namespace' => $namespace,
            'embedding_cache' => $this->embeddings instanceof CachedEmbeddingProvider
                ? $this->cacheStatsSince($cacheBefore ?? [])
                : null,
        ];
            $this->traces?->finishSpan($span, ['rag.summary' => $summary]);

            return $summary;
        } catch (\Throwable $exception) {
            $this->traces?->failSpan($span, $exception);
            throw $exception;
        }
    }

    public function deleteDocument(string $documentId, ?string $namespace = null): void
    {
        $this->store->deleteByDocument($documentId, $namespace);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    protected function mergeSummary(array $summary, array $batch): array
    {
        $summary['documents'] += $batch['documents'];
        $summary['chunks'] += $batch['chunks'];
        $summary['embedding_cache'] = $this->mergeCacheStats(
            $summary['embedding_cache'] ?? null,
            $batch['embedding_cache'] ?? null,
        );

        return $summary;
    }

    /**
     * @param array<string, int> $before
     * @return array<string, int>
     */
    protected function cacheStatsSince(array $before): array
    {
        $after = $this->embeddings instanceof CachedEmbeddingProvider ? $this->embeddings->stats() : [];
        $stats = [];

        foreach ($after as $key => $value) {
            $stats[$key] = $value - ($before[$key] ?? 0);
        }

        $stats['saved_embeddings'] = $stats['hits'] ?? 0;

        return $stats;
    }

    /**
     * @param array<string, int>|null $current
     * @param array<string, int>|null $next
     * @return array<string, int>|null
     */
    protected function mergeCacheStats(?array $current, ?array $next): ?array
    {
        if ($current === null) {
            return $next;
        }

        if ($next === null) {
            return $current;
        }

        foreach ($next as $key => $value) {
            $current[$key] = ($current[$key] ?? 0) + $value;
        }

        return $current;
    }
}
