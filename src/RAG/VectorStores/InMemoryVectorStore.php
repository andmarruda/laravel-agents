<?php

namespace Andmarruda\LaravelAgents\RAG\VectorStores;

use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;
use Andmarruda\LaravelAgents\RAG\Support\VectorMath;
use Andmarruda\LaravelAgents\RAG\Metadata\MetadataFilter;

class InMemoryVectorStore implements VectorStore
{
    /**
     * @var array<string, array<string, VectorRecord>>
     */
    protected array $records = [];

    public function upsert(array $records, ?string $namespace = null): void
    {
        $key = $namespace ?? 'default';

        foreach ($records as $record) {
            $this->records[$key][$record->id] = $record;
        }
    }

    public function replaceDocument(string $documentId, array $records, ?string $namespace = null): void
    {
        $key = $namespace ?? 'default';
        $replacement = array_filter(
            $this->records[$key] ?? [],
            fn (VectorRecord $record) => $record->documentId !== $documentId,
        );

        foreach ($records as $record) {
            $replacement[$record->id] = $record;
        }

        $this->records[$key] = $replacement;
    }

    public function search(array $vector, int $limit = 5, array $filters = [], ?string $namespace = null): array
    {
        $filters = MetadataFilter::normalize($filters);
        $records = array_filter(
            $this->records[$namespace ?? 'default'] ?? [],
            fn (VectorRecord $record) => $this->matches($record->metadata, $filters),
        );
        $results = array_map(fn (VectorRecord $record) => new SearchResult(
            id: $record->id,
            content: $record->content,
            score: VectorMath::cosine($vector, $record->vector),
            metadata: $record->metadata,
            documentId: $record->documentId,
        ), $records);

        usort($results, fn (SearchResult $a, SearchResult $b) => $b->score <=> $a->score);

        return array_slice($results, 0, max(0, $limit));
    }

    public function deleteByDocument(string $documentId, ?string $namespace = null): void
    {
        $key = $namespace ?? 'default';

        foreach ($this->records[$key] ?? [] as $id => $record) {
            if ($record->documentId === $documentId) {
                unset($this->records[$key][$id]);
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $filters
     */
    protected function matches(array $metadata, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (! array_key_exists($key, $metadata) || $metadata[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
