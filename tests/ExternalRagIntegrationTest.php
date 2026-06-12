<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\RAG\Data\VectorRecord;
use Andmarruda\LaravelAgents\RAG\VectorStores\QdrantVectorStore;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('external-rag')]
class ExternalRagIntegrationTest extends TestCase
{
    public function test_qdrant_live_round_trip_when_configured(): void
    {
        $url = getenv('QDRANT_URL');
        if (! is_string($url) || $url === '') {
            $this->markTestSkipped('Set QDRANT_URL to run live Qdrant integration.');
        }

        $namespace = 'integration-'.bin2hex(random_bytes(4));
        $documentId = 'document-'.bin2hex(random_bytes(4));
        $store = new QdrantVectorStore(
            $url,
            getenv('QDRANT_COLLECTION') ?: 'laravel_agents_integration',
            getenv('QDRANT_API_KEY') ?: null,
            new Factory(),
        );
        $store->replaceDocument($documentId, [
            new VectorRecord('chunk-'.$documentId, [1.0, 0.0], 'Laravel agent', ['label' => 'ação'], $documentId),
        ], $namespace);

        $results = $store->search([1.0, 0.0], filters: ['label' => 'ação'], namespace: $namespace);

        $this->assertSame($documentId, $results[0]->documentId);
        $store->deleteByDocument($documentId, $namespace);
    }

    public function test_pgvector_live_round_trip_when_configured(): void
    {
        if (! class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped('Install illuminate/database to run live pgvector integration.');
        }

        $database = getenv('PGVECTOR_DATABASE');
        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Set PGVECTOR_DATABASE to run live pgvector integration.');
        }

        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'pgsql',
            'host' => getenv('PGVECTOR_HOST') ?: '127.0.0.1',
            'port' => getenv('PGVECTOR_PORT') ?: '5432',
            'database' => $database,
            'username' => getenv('PGVECTOR_USERNAME') ?: 'postgres',
            'password' => getenv('PGVECTOR_PASSWORD') ?: '',
        ]);
        $connection = $capsule->getConnection();
        $table = 'agent_rag_integration_'.bin2hex(random_bytes(4));
        $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
        $connection->statement(
            "CREATE TABLE {$table} (
                id VARCHAR(64) NOT NULL,
                namespace VARCHAR(255) NOT NULL,
                document_id VARCHAR(64),
                content TEXT NOT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                embedding vector(4) NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (id, namespace)
            )"
        );

        try {
            $store = new \Andmarruda\LaravelAgents\RAG\VectorStores\PgVectorStore($connection, $table);
            $documentId = 'document-'.bin2hex(random_bytes(4));
            $store->replaceDocument($documentId, [
                new VectorRecord('chunk-'.$documentId, [1.0, 0.0, 0.0, 0.0], 'Laravel agent', ['label' => 'ação'], $documentId),
            ], 'integration');

            $results = $store->search([1.0, 0.0, 0.0, 0.0], filters: ['label' => 'ação'], namespace: 'integration');

            $this->assertSame($documentId, $results[0]->documentId);
        } finally {
            $connection->statement("DROP TABLE IF EXISTS {$table}");
        }
    }
}
