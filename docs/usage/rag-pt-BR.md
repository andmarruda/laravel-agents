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

Para embeddings OpenAI, configure `OPENAI_API_KEY`.

O cache de embeddings fica ativo por padrão. As chaves incluem provider, modelo, dimensões, versão do cache e hash do conteúdo, então chunks inalterados não geram novas chamadas à API.

Altere a versão do cache quando o comportamento de normalização de texto mudar. Alterações de provider, modelo e dimensões invalidam entradas automaticamente. Alterações na estratégia de chunking reutilizam embeddings apenas quando o conteúdo final do chunk é idêntico.

## Document Loaders

```php
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StreamingFileDocumentLoader;
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

O roteador de chunking seleciona estratégias por MIME type, extensão ou pelo metadata explícito `chunking_strategy`. A estratégia `semantic` preserva limites de linguagem natural, enquanto `code` mantém declarações como funções, classes e métodos juntas quando possível. Estratégias da aplicação podem ser registradas em `agents.rag.chunking.strategies`.

Use `minimumScore` em `retrieve` ou `AGENTS_RAG_MINIMUM_SCORE` para remover resultados semanticamente distantes. Um array vazio significa que nenhum contexto suficientemente relevante foi encontrado. O `RetrieverTool` resolvido pelo container suporta as políticas `explicit_empty`, `empty_array` e `exception` através de `AGENTS_RAG_ZERO_RESULT_POLICY`. Use `ZeroResultPolicy('callback', $callable)` para um fallback definido pela aplicação.

Filtros de metadata são portáveis, exatos e aceitam valores escalares ou nulos. Chaves são validadas e filtros aninhados são rejeitados. Use `agents.rag.metadata` para impor campos e tipos específicos da aplicação.

## Indexação Em Background E Arquivos Grandes

`StreamingFileDocumentLoader` lê arquivos de texto grandes linha por linha, cria segmentos limitados e nunca trunca conteúdo silenciosamente.

```php
LaravelAgents::indexAsync(
    loader: new StreamingFileDocumentLoader(storage_path('knowledge/large.md')),
    namespace: 'product-docs',
    queue: 'rag-indexing',
    batchDocuments: 25,
);
```

Os jobs são idempotentes, usam três tentativas com backoff, retomam a partir de checkpoints de lote persistidos em cache e emitem os eventos `DocumentIndexingStarted`, `DocumentIndexingProgressed`, `DocumentIndexingCompleted` e `DocumentIndexingFailed`.

## Limites, Observabilidade E Benchmarks

Limites configuráveis rejeitam documentos, texto extraído, quantidade de chunks e chunks individuais excessivos antes de embeddings ou escritas no vector store.

Quando o RAG executa dentro de um trace observado ativo, indexação e retrieval adicionam spans `rag.index` e `rag.retrieve`. Os spans de indexação incluem resumo e economia do cache.

Execute o benchmark local determinístico, sem APIs externas:

```bash
composer benchmark:rag
```

Execute a integração viva com stores externos quando o Qdrant estiver disponível:

```bash
QDRANT_URL=http://localhost:6333 composer test:rag-external
```

O mesmo comando executa a integração pgvector quando `illuminate/database` estiver instalado e `PGVECTOR_DATABASE`, além dos opcionais `PGVECTOR_HOST`, `PGVECTOR_PORT`, `PGVECTOR_USERNAME` e `PGVECTOR_PASSWORD`, estiverem configurados.

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
