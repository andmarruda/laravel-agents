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
AGENTS_RAG_CHUNKING_STRATEGY=semantic
AGENTS_RAG_EMBEDDING_CACHE=true
AGENTS_RAG_EMBEDDING_CACHE_DRIVER=laravel
AGENTS_RAG_MINIMUM_SCORE=0.75
AGENTS_RAG_ZERO_RESULT_POLICY=explicit_empty
AGENTS_RAG_MAX_DOCUMENT_BYTES=10485760
AGENTS_RAG_MAX_EXTRACTED_TEXT_BYTES=10485760
AGENTS_RAG_MAX_CHUNKS_PER_DOCUMENT=10000
AGENTS_RAG_MAX_CHUNK_BYTES=100000
```

For OpenAI embeddings, configure `OPENAI_API_KEY`.

## Document Loaders

```php
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StreamingFileDocumentLoader;
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

Use `StreamingFileDocumentLoader` for large text files. It reads line-by-line, creates bounded document segments, and fails explicitly when the configured streaming limit is exceeded. It never silently truncates content.

## Content-Aware Chunking

The default `semantic` strategy preserves natural-language paragraph and sentence boundaries. The `code` strategy detects declarations and keeps functions, classes, methods, interfaces, traits, and enums together where practical. The automatic router selects strategies from MIME type, extension, or an explicit `chunking_strategy` metadata value.

Register application-specific strategies in `agents.rag.chunking.strategies` and map extensions or MIME types to them. Custom strategies must implement `Chunker`.

## Incremental Indexing

Embedding caching is enabled by default. Cache keys include the provider, model, dimensions, cache version, and content hash, so unchanged chunks skip embedding API calls. Use the `laravel` cache driver for a persistent shared cache. Index summaries include hits, misses, saved calls, saved embeddings, and estimated saved tokens.

Change the cache version whenever text normalization behavior changes. Provider, model, and dimension changes invalidate entries automatically. Chunking strategy changes reuse an embedding only when the resulting chunk content is identical.

## Index And Retrieve

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$summary = LaravelAgents::indexer()->index($loader, namespace: 'product-docs');

$results = LaravelAgents::retriever(namespace: 'product-docs')->retrieve(
    query: 'How do tools work?',
    limit: 5,
    filters: ['tenant_id' => 10],
    minimumScore: 0.75,
);

foreach ($results as $result) {
    echo $result->content;
    echo $result->score;
}
```

Documents with a source use a stable document ID. Re-indexing the same source removes stale chunks before upserting the new chunks.

Results below the configured or per-query minimum score are removed. An empty array means that no sufficiently relevant context was found. `RetrieverTool` returns an explicit `found: false` result in this case so agents do not assume context exists.

The container-resolved `RetrieverTool` supports `explicit_empty`, `empty_array`, and `exception` zero-result policies through `AGENTS_RAG_ZERO_RESULT_POLICY`. Construct `ZeroResultPolicy('callback', $callable)` for an application-defined fallback.

Metadata filters are portable exact-match filters and accept scalar or null values. Filter keys are validated, and nested filter objects are rejected. Configure `agents.rag.metadata` to enforce application-specific metadata fields and types.

## Background Indexing

Dispatch indexing in bounded Laravel queue batches:

```php
LaravelAgents::indexAsync(
    loader: new StreamingFileDocumentLoader(storage_path('knowledge/large.md')),
    namespace: 'product-docs',
    queue: 'rag-indexing',
    batchDocuments: 25,
);
```

Jobs are idempotent, retry three times with backoff, resume from cache-backed batch checkpoints, and emit `DocumentIndexingStarted`, `DocumentIndexingProgressed`, `DocumentIndexingCompleted`, and `DocumentIndexingFailed` events.

## Limits, Observability, And Benchmarks

Configurable limits reject oversized documents, extracted text, chunk counts, and individual chunks before embedding or vector-store writes.

When RAG runs inside an active observed trace, indexing and retrieval add `rag.index` and `rag.retrieve` spans. Index spans include cache savings and result summaries.

Run the local deterministic benchmark without external APIs:

```bash
composer benchmark:rag
```

Run live external-store integration when Qdrant is available:

```bash
QDRANT_URL=http://localhost:6333 composer test:rag-external
```

The same command runs pgvector integration when `illuminate/database` is installed and `PGVECTOR_DATABASE` plus optional `PGVECTOR_HOST`, `PGVECTOR_PORT`, `PGVECTOR_USERNAME`, and `PGVECTOR_PASSWORD` are configured.

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
