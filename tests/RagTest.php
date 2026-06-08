<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\RAG\Chunking\RecursiveCharacterChunker;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Embeddings\OpenAiEmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\UrlDocumentLoader;
use Andmarruda\LaravelAgents\RAG\RagIndexer;
use Andmarruda\LaravelAgents\RAG\Retriever;
use Andmarruda\LaravelAgents\RAG\Tools\RetrieverTool;
use Andmarruda\LaravelAgents\RAG\VectorStores\InMemoryVectorStore;
use Andmarruda\LaravelAgents\RAG\VectorStores\QdrantVectorStore;
use Andmarruda\LaravelAgents\RAG\Workflows\IndexDocumentsStep;
use Andmarruda\LaravelAgents\RAG\Workflows\RetrieveStep;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeEmbeddingProvider;
use Andmarruda\LaravelAgents\Workflows\Workflow;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RagTest extends TestCase
{
    public function test_document_ids_are_stable_by_source_and_metadata_is_normalized(): void
    {
        $first = Document::fromText('Old content', ['published_at' => new DateTimeImmutable('2026-01-01')], 'docs/guide.md');
        $second = Document::fromText('New content', [], 'docs/guide.md');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-01-01T00:00:00+00:00', $first->metadata['published_at']);
        $this->assertNotSame($first->checksum, $second->checksum);
    }

    public function test_string_array_and_file_loaders_create_documents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag');
        file_put_contents($path, 'File knowledge');

        $string = (new StringDocumentLoader('String knowledge', ['type' => 'string']))->load();
        $array = (new ArrayDocumentLoader([['content' => 'Array knowledge', 'source' => 'array:1']]))->load();
        $file = (new FileDocumentLoader($path))->load();

        $this->assertSame('String knowledge', $string[0]->content);
        $this->assertSame('array:1', $array[0]->source);
        $this->assertSame('File knowledge', $file[0]->content);
        $this->assertSame(basename($path), $file[0]->metadata['filename']);

        unlink($path);
    }

    public function test_recursive_chunker_creates_deterministic_overlapping_chunks(): void
    {
        $document = Document::fromText(str_repeat('Laravel agents are useful. ', 20), source: 'guide');
        $chunker = new RecursiveCharacterChunker(chunkSize: 100, overlap: 20);

        $first = $chunker->chunk($document);
        $second = $chunker->chunk($document);

        $this->assertGreaterThan(1, count($first));
        $this->assertSame($first[0]->id, $second[0]->id);
        $this->assertSame($document->id, $first[0]->documentId);
        $this->assertLessThanOrEqual(100, strlen($first[0]->content));
    }

    public function test_chunker_rejects_invalid_overlap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecursiveCharacterChunker(chunkSize: 100, overlap: 100);
    }

    public function test_indexer_and_retriever_search_and_filter_documents(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        $indexer = new RagIndexer(new RecursiveCharacterChunker(100, 10), $embeddings, $store);
        $documents = [
            Document::fromText('Laravel agents use tools and workflows.', ['tenant' => 'a'], 'a'),
            Document::fromText('Database indexes improve query performance.', ['tenant' => 'b'], 'b'),
        ];

        $summary = $indexer->indexDocuments($documents, 'knowledge');
        $retriever = new Retriever($embeddings, $store, namespace: 'knowledge');
        $results = $retriever->retrieve('Laravel agent', filters: ['tenant' => 'a']);

        $this->assertSame(2, $summary['documents']);
        $this->assertGreaterThanOrEqual(2, $summary['chunks']);
        $this->assertSame($documents[0]->id, $results[0]->documentId);
        $this->assertSame('a', $results[0]->metadata['tenant']);
    }

    public function test_reindexing_same_source_removes_stale_chunks(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        $indexer = new RagIndexer(new RecursiveCharacterChunker(40, 5), $embeddings, $store);
        $retriever = new Retriever($embeddings, $store, defaultLimit: 20, namespace: 'docs');

        $indexer->indexDocuments([Document::fromText(str_repeat('Laravel agent database. ', 10), source: 'guide')], 'docs');
        $indexer->indexDocuments([Document::fromText('Laravel agent.', source: 'guide')], 'docs');

        $this->assertCount(1, $retriever->retrieve('Laravel agent', 20));
    }

    public function test_failed_reindex_keeps_previous_document_available(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        $indexer = new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store);
        $indexer->indexDocuments([Document::fromText('Laravel agent original', source: 'guide')]);
        $failing = new class implements EmbeddingProvider {
            public function embed(array $texts): array
            {
                return [];
            }

            public function dimensions(): ?int
            {
                return 4;
            }
        };

        try {
            (new RagIndexer(new RecursiveCharacterChunker(), $failing, $store))
                ->indexDocuments([Document::fromText('Replacement', source: 'guide')]);
            $this->fail('Expected reindex failure.');
        } catch (\RuntimeException) {
            $results = (new Retriever($embeddings, $store))->retrieve('Laravel agent');

            $this->assertSame('Laravel agent original', $results[0]->content);
        }
    }

    public function test_retriever_tool_returns_serializable_results(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        (new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store))
            ->index(new StringDocumentLoader('Laravel agent context', source: 'guide'));
        $tool = new RetrieverTool(new Retriever($embeddings, $store));

        $results = $tool->handle(['query' => 'Laravel agent']);

        $this->assertSame('retrieve_context', $tool->name());
        $this->assertSame('Laravel agent context', $results[0]['content']);
        $this->assertArrayHasKey('score', $results[0]);
    }

    public function test_rag_steps_are_usable_in_workflows(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        $indexer = new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store);
        $retriever = new Retriever($embeddings, $store);

        $indexed = Workflow::make()->then(new IndexDocumentsStep($indexer))->run(['Laravel agent workflow']);
        $retrieved = Workflow::make()->then(new RetrieveStep($retriever))->run('Laravel agent');

        $this->assertSame(1, $indexed->data['documents']);
        $this->assertSame('Laravel agent workflow', $retrieved->data[0]['content']);
    }

    public function test_openai_embedding_provider_batches_and_preserves_order(): void
    {
        $http = new Factory();
        $http->fakeSequence()
            ->push(['data' => [
                ['index' => 1, 'embedding' => [0, 1]],
                ['index' => 0, 'embedding' => [1, 0]],
            ]])
            ->push(['data' => [
                ['index' => 0, 'embedding' => [1, 1]],
            ]]);
        $provider = new OpenAiEmbeddingProvider(
            model: 'text-embedding-3-small',
            config: ['api_key' => 'test', 'base_url' => 'https://api.example.test/v1'],
            http: $http,
            batchSize: 2,
            configuredDimensions: 2,
        );

        $vectors = $provider->embed(['one', 'two', 'three']);

        $this->assertSame([[1.0, 0.0], [0.0, 1.0], [1.0, 1.0]], $vectors);
        $http->assertSentCount(2);
    }

    public function test_qdrant_store_creates_collection_upserts_and_searches(): void
    {
        $http = new Factory();
        $http->fakeSequence()
            ->push([], 404)
            ->push(['result' => true])
            ->push(['result' => true])
            ->push(['result' => [[
                'id' => 'point-id',
                'score' => 0.95,
                'payload' => [
                    'record_id' => 'chunk-id',
                    'document_id' => 'doc-id',
                    'content' => 'Laravel agent',
                    'metadata' => ['tenant' => 'a'],
                ],
            ]]]);
        $store = new QdrantVectorStore('https://qdrant.test', 'docs', http: $http);
        $record = new \Andmarruda\LaravelAgents\RAG\Data\VectorRecord(
            'chunk-id',
            [1, 0],
            'Laravel agent',
            ['tenant' => 'a'],
            'doc-id',
        );

        $store->upsert([$record], 'knowledge');
        $results = $store->search([1, 0], filters: ['tenant' => 'a'], namespace: 'knowledge');

        $this->assertSame('chunk-id', $results[0]->id);
        $this->assertSame(0.95, $results[0]->score);
        $http->assertSentCount(4);
    }

    public function test_url_loader_enforces_host_allowlist_before_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not allowed');

        (new UrlDocumentLoader(
            'https://internal.example.test/secrets',
            http: new Factory(),
            allowedHosts: ['docs.example.test'],
        ))->load();
    }
}
