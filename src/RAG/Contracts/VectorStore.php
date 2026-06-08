<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;

interface VectorStore
{
    /**
     * @param array<int, VectorRecord> $records
     */
    public function upsert(array $records, ?string $namespace = null): void;

    /**
     * @param array<int, VectorRecord> $records
     */
    public function replaceDocument(string $documentId, array $records, ?string $namespace = null): void;

    /**
     * @param array<int, float|int> $vector
     * @param array<string, mixed> $filters
     * @return array<int, SearchResult>
     */
    public function search(array $vector, int $limit = 5, array $filters = [], ?string $namespace = null): array;

    public function deleteByDocument(string $documentId, ?string $namespace = null): void;
}
