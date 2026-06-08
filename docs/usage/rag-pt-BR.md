# Guia De Uso: RAG

O RAG do Laravel Agents fornece ingestão determinística de documentos, chunking, embeddings, armazenamento vetorial, retrieval, ferramentas para agentes e steps para workflows.

## Configuração

```env
AGENTS_RAG_EMBEDDING_MODEL=openai/text-embedding-3-small
AGENTS_RAG_EMBEDDING_DIMENSIONS=1536
AGENTS_RAG_EMBEDDING_BATCH_SIZE=100
AGENTS_RAG_CHUNK_SIZE=1000
AGENTS_RAG_CHUNK_OVERLAP=150
AGENTS_RAG_VECTOR_STORE=pgvector
```

Para embeddings OpenAI, configure `OPENAI_API_KEY`.

## Document Loaders

```php
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\UrlDocumentLoader;

$loader = new StringDocumentLoader(
    content: 'Laravel Agents suporta retrieval.',
    metadata: ['tenant_id' => 10, 'category' => 'docs'],
    source: 'docs:introduction',
);

$loader = new FileDocumentLoader(storage_path('knowledge/guide.md'));

$loader = new ArrayDocumentLoader([
    ['content' => 'Primeiro documento', 'source' => 'docs:first'],
    ['content' => 'Segundo documento', 'source' => 'docs:second'],
]);

$loader = new UrlDocumentLoader(
    url: 'https://docs.example.com/agents.txt',
    allowedHosts: ['docs.example.com'],
);
```

Loaders de arquivo e URL usam limite padrão de 10 MB. O loader de URL aceita apenas HTTP(S), desabilita redirects e aceita uma allowlist explícita de hosts. Sempre use allowlist quando usuários puderem influenciar URLs.

## Indexação E Retrieval

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$summary = LaravelAgents::indexer()->index($loader, namespace: 'product-docs');

$results = LaravelAgents::retriever(namespace: 'product-docs')->retrieve(
    query: 'Como ferramentas funcionam?',
    limit: 5,
    filters: ['tenant_id' => 10],
);
```

Documentos com source usam ID estável. Reindexar o mesmo source remove chunks antigos antes do upsert dos novos chunks.

## Ferramenta Para Agentes

```php
use Andmarruda\LaravelAgents\RAG\Tools\RetrieverTool;

$tool = new RetrieverTool(
    LaravelAgents::retriever(namespace: 'product-docs')
);
```

A ferramenta aceita `query`, `limit` opcional e `filters` opcionais de metadata por igualdade.

## Steps Para Workflows

```php
use Andmarruda\LaravelAgents\RAG\Workflows\IndexDocumentsStep;
use Andmarruda\LaravelAgents\RAG\Workflows\RetrieveStep;

$indexResponse = LaravelAgents::workflow()
    ->then(new IndexDocumentsStep(LaravelAgents::indexer(), 'product-docs'))
    ->run($loader);

$retrieveResponse = LaravelAgents::workflow()
    ->then(new RetrieveStep(LaravelAgents::retriever(namespace: 'product-docs')))
    ->run(['query' => 'instalação', 'limit' => 3]);
```

## PostgreSQL E pgvector

Instale pgvector no PostgreSQL, configure as dimensões do modelo e publique a migration dedicada:

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

O adapter usa distância cosseno, índice HNSW, filtros JSONB, namespaces e upserts idempotentes.

## Qdrant

```env
AGENTS_RAG_VECTOR_STORE=qdrant
QDRANT_URL=http://localhost:6333
QDRANT_API_KEY=
QDRANT_COLLECTION=laravel_agents
AGENTS_RAG_QDRANT_AUTO_CREATE_COLLECTION=true
```

O adapter Qdrant suporta criação automática da collection, namespaces, filtros de payload, upserts, busca e remoção por documento.

## Providers E Stores Customizados

Implemente `EmbeddingProvider` para outro serviço de embeddings. Para outro vector store, implemente `VectorStore` e configure sua classe em `agents.rag.vector_store.stores`.
