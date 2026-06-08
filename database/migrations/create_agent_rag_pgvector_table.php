<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) (config('agents.rag.embeddings.dimensions') ?: 1536);
        $table = (string) config('agents.rag.vector_store.stores.pgvector.table', 'agent_rag_vectors');

        if ($dimensions < 1) {
            throw new \RuntimeException('RAG embedding dimensions must be at least 1.');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \RuntimeException('RAG pgvector table name is invalid.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement("
            CREATE TABLE {$table} (
                id varchar(64) NOT NULL,
                namespace varchar(255) NOT NULL DEFAULT 'default',
                document_id varchar(64) NULL,
                content text NOT NULL,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                embedding vector({$dimensions}) NOT NULL,
                created_at timestamp without time zone NULL,
                updated_at timestamp without time zone NULL,
                PRIMARY KEY (id, namespace)
            )
        ");
        DB::statement("CREATE INDEX {$table}_document_namespace_idx ON {$table} (document_id, namespace)");
        DB::statement("CREATE INDEX {$table}_metadata_idx ON {$table} USING gin (metadata)");
        DB::statement("CREATE INDEX {$table}_embedding_idx ON {$table} USING hnsw (embedding vector_cosine_ops)");
    }

    public function down(): void
    {
        $table = (string) config('agents.rag.vector_store.stores.pgvector.table', 'agent_rag_vectors');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \RuntimeException('RAG pgvector table name is invalid.');
        }

        DB::statement("DROP TABLE IF EXISTS {$table}");
    }
};
