# RAG Usage Guide

Laravel Agents RAG provides deterministic document ingestion, chunking, embeddings, vector storage, retrieval, agent tools, and workflow steps.

## Configuration

```env
AGENTS_RAG_EMBEDDING_MODEL=openai/text-embedding-3-small
AGENTS_RAG_EMBEDDING_DIMENSIONS=1536
AGENTS_RAG_EMBEDDING_BATCH_SIZE=100
AGENTS_RAG_CHUNK_SIZE=1000
AGENTS_RAG_CHUNK_OVERLAP=150
AGENTS_RAG_VECTOR_STORE=pgvector
```

For OpenAI embeddings, configure `OPENAI_API_KEY`.

## Document Loaders

```php
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\UrlDocumentLoader;

$loader = new StringDocumentLoader(
    content: 'Laravel Agents supports retrieval.',
    metadata: ['tenant_id' => 10, 'category' => 'docs'],
    source: 'docs:introduction',
);

$loader = new FileDocumentLoader(storage_path('knowledge/guide.md'));

$loader = new ArrayDocumentLoader([
    ['content' => 'First document', 'source' => 'docs:first'],
    ['content' => 'Second document', 'source' => 'docs:second'],
]);

$loader = new UrlDocumentLoader(
    url: 'https://docs.example.com/agents.txt',
    allowedHosts: ['docs.example.com'],
);
```

File and URL loaders default to a 10 MB limit. URL loading accepts only HTTP(S), disables redirects, and supports an explicit host allowlist. Always use an allowlist when URLs can be influenced by users.

## Index And Retrieve

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$summary = LaravelAgents::indexer()->index($loader, namespace: 'product-docs');

$results = LaravelAgents::retriever(namespace: 'product-docs')->retrieve(
    query: 'How do tools work?',
    limit: 5,
    filters: ['tenant_id' => 10],
);

foreach ($results as $result) {
    echo $result->content;
    echo $result->score;
}
```

Documents with a source use a stable document ID. Re-indexing the same source removes stale chunks before upserting the new chunks.

## Retriever Tool

```php
use Andmarruda\LaravelAgents\RAG\Tools\RetrieverTool;

final class SupportAgent extends Agent
{
    public function configure(): void
    {
        $this->withTools([
            new RetrieverTool(
                LaravelAgents::retriever(namespace: 'product-docs')
            ),
        ]);
    }
}
```

The tool accepts `query`, optional `limit`, and optional exact-match metadata `filters`.

## Workflow Steps

```php
use Andmarruda\LaravelAgents\RAG\Workflows\IndexDocumentsStep;
use Andmarruda\LaravelAgents\RAG\Workflows\RetrieveStep;

$indexResponse = LaravelAgents::workflow()
    ->then(new IndexDocumentsStep(LaravelAgents::indexer(), 'product-docs'))
    ->run($loader);

$retrieveResponse = LaravelAgents::workflow()
    ->then(new RetrieveStep(LaravelAgents::retriever(namespace: 'product-docs')))
    ->run(['query' => 'installation', 'limit' => 3]);
```

## PostgreSQL And pgvector

Install pgvector in PostgreSQL, configure dimensions to match the embedding model, then publish the dedicated migration:

```bash
php artisan vendor:publish --tag=agents-rag-pgvector-migrations
php artisan migrate
```

```env
AGENTS_RAG_VECTOR_STORE=pgvector
AGENTS_RAG_PGVECTOR_CONNECTION=pgsql
AGENTS_RAG_PGVECTOR_TABLE=agent_rag_vectors
AGENTS_RAG_EMBEDDING_DIMENSIONS=1536
```

The adapter uses cosine distance, an HNSW index, JSONB metadata filters, namespaces, and idempotent upserts.

## Qdrant

```env
AGENTS_RAG_VECTOR_STORE=qdrant
QDRANT_URL=http://localhost:6333
QDRANT_API_KEY=
QDRANT_COLLECTION=laravel_agents
AGENTS_RAG_QDRANT_AUTO_CREATE_COLLECTION=true
```

The Qdrant adapter supports collection auto-creation, namespaces, payload filters, upserts, search, and deletion by document.

## Custom Providers And Stores

Implement `EmbeddingProvider` for another embedding API. For a custom vector store, implement `VectorStore` and configure its class:

```php
'vector_store' => [
    'default' => 'custom',
    'stores' => [
        'custom' => ['class' => App\Rag\CustomVectorStore::class],
    ],
],
```
