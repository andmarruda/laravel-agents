<?php

namespace Andmarruda\LaravelAgents\RAG\VectorStores;

use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

class PgVectorStore implements VectorStore
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'agent_rag_vectors',
    ) {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('PgVector table name is invalid.');
        }
    }

    public function upsert(array $records, ?string $namespace = null): void
    {
        foreach ($records as $record) {
            $this->connection->statement(
                "INSERT INTO {$this->table} (id, namespace, document_id, content, metadata, embedding, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?::jsonb, ?::vector, NOW(), NOW())
                 ON CONFLICT (id, namespace) DO UPDATE SET document_id = EXCLUDED.document_id, content = EXCLUDED.content,
                    metadata = EXCLUDED.metadata, embedding = EXCLUDED.embedding, updated_at = NOW()",
                [
                    $record->id,
                    $namespace ?? 'default',
                    $record->documentId,
                    $record->content,
                    json_encode($record->metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $this->vector($record->vector),
                ],
            );
        }
    }

    public function replaceDocument(string $documentId, array $records, ?string $namespace = null): void
    {
        $this->connection->transaction(function () use ($documentId, $records, $namespace): void {
            $this->deleteByDocument($documentId, $namespace);
            $this->upsert($records, $namespace);
        });
    }

    public function search(array $vector, int $limit = 5, array $filters = [], ?string $namespace = null): array
    {
        $bindings = [$this->vector($vector), $namespace ?? 'default'];
        $where = 'namespace = ?';

        foreach ($filters as $key => $value) {
            $where .= ' AND metadata ->> ? = ?';
            $bindings[] = (string) $key;
            $bindings[] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $rows = $this->connection->select(
            "SELECT id, document_id, content, metadata, 1 - (embedding <=> ?::vector) AS score
             FROM {$this->table} WHERE {$where} ORDER BY embedding <=> ?::vector LIMIT ?",
            [...$bindings, $this->vector($vector), max(0, $limit)],
        );

        return array_map(fn (object $row) => new SearchResult(
            id: (string) $row->id,
            content: (string) $row->content,
            score: (float) $row->score,
            metadata: json_decode((string) $row->metadata, true) ?: [],
            documentId: $row->document_id ? (string) $row->document_id : null,
        ), $rows);
    }

    public function deleteByDocument(string $documentId, ?string $namespace = null): void
    {
        $this->connection->delete(
            "DELETE FROM {$this->table} WHERE document_id = ? AND namespace = ?",
            [$documentId, $namespace ?? 'default'],
        );
    }

    /**
     * @param array<int, float|int> $vector
     */
    protected function vector(array $vector): string
    {
        if ($vector === []) {
            throw new InvalidArgumentException('Vector cannot be empty.');
        }

        return '['.implode(',', array_map(fn ($value) => (string) (float) $value, $vector)).']';
    }
}
