<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\RAG\Chunking\RecursiveCharacterChunker;
use Andmarruda\LaravelAgents\RAG\Chunking\ChunkingStrategyRouter;
use Andmarruda\LaravelAgents\RAG\Chunking\CodeChunker;
use Andmarruda\LaravelAgents\RAG\Chunking\SemanticTextChunker;
use Andmarruda\LaravelAgents\RAG\Chunking\PhpCodeChunker;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\StreamingDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Embeddings\CachedEmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Embeddings\InMemoryEmbeddingCache;
use Andmarruda\LaravelAgents\RAG\Jobs\IndexDocumentLoaderJob;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Embeddings\OpenAiEmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Loaders\ArrayDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\FileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StringDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\StreamingFileDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Loaders\UrlDocumentLoader;
use Andmarruda\LaravelAgents\RAG\RagIndexer;
use Andmarruda\LaravelAgents\RAG\Retriever;
use Andmarruda\LaravelAgents\RAG\Metadata\StrictMetadataSchema;
use Andmarruda\LaravelAgents\RAG\Tools\RetrieverTool;
use Andmarruda\LaravelAgents\RAG\Tools\ZeroResultPolicy;
use Andmarruda\LaravelAgents\RAG\Data\IndexingLimits;
use Andmarruda\LaravelAgents\RAG\Exceptions\NoRelevantContextException;
use Andmarruda\LaravelAgents\RAG\Jobs\LaravelIndexingCheckpointStore;
use Andmarruda\LaravelAgents\RAG\Jobs\InMemoryIndexingCheckpointStore;
use Andmarruda\LaravelAgents\Observability\Exporters\NullTraceExporter;
use Andmarruda\LaravelAgents\Observability\Stores\InMemoryTraceStore;
use Andmarruda\LaravelAgents\Observability\Support\CostCalculator;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Andmarruda\LaravelAgents\RAG\VectorStores\InMemoryVectorStore;
use Andmarruda\LaravelAgents\RAG\VectorStores\QdrantVectorStore;
use Andmarruda\LaravelAgents\RAG\Workflows\IndexDocumentsStep;
use Andmarruda\LaravelAgents\RAG\Workflows\RetrieveStep;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeEmbeddingProvider;
use Andmarruda\LaravelAgents\Workflows\Workflow;
use Andmarruda\LaravelAgents\LaravelAgentsManager;
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

    public function test_content_aware_chunking_selects_code_strategy(): void
    {
        $router = new ChunkingStrategyRouter(
            ['semantic' => new SemanticTextChunker(100, 10), 'code' => new CodeChunker(100, 10)],
            extensions: ['php' => 'code'],
        );
        $chunks = $router->chunk(Document::fromText(
            "<?php\nfunction first() {}\nfunction second() {}",
            ['filename' => 'example.php'],
            'example.php',
            'text/x-php',
        ));

        $this->assertSame('code', $chunks[0]->metadata['chunking_strategy']);
        $this->assertStringContainsString('function first', $chunks[0]->content);
    }

    public function test_semantic_chunker_preserves_markdown_blocks_and_lists(): void
    {
        $content = "# Heading\n\nIntro paragraph.\n\n- first\n- second\n\n## Next\n\nFinal paragraph.";
        $chunks = (new SemanticTextChunker(45, 5))->chunk(Document::fromText($content));

        $this->assertStringContainsString("# Heading\n\nIntro paragraph.", $chunks[0]->content);
        $this->assertStringContainsString("- first\n- second", $chunks[0]->content);
        $this->assertLessThanOrEqual(45, strlen($chunks[0]->content));
        $this->assertSame('semantic', $chunks[0]->metadata['chunking_strategy']);
    }

    public function test_php_chunker_preserves_top_level_class_and_function_boundaries(): void
    {
        $content = <<<'PHP'
<?php

final class Service
{
    public function first(): void {}
    public function second(): void {}
}

function helper(): void {}
PHP;
        $chunks = (new PhpCodeChunker(500, 10))->chunk(Document::fromText($content, source: 'Service.php', mimeType: 'text/x-php'));

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('class Service', $chunks[0]->content);
        $this->assertStringContainsString('function first', $chunks[0]->content);
        $this->assertStringContainsString('function helper', $chunks[1]->content);
        $this->assertSame('php', $chunks[0]->metadata['chunking_strategy']);
    }

    public function test_embedding_cache_skips_unchanged_content(): void
    {
        $provider = new class implements EmbeddingProvider {
            public int $calls = 0;

            public function embed(array $texts): array
            {
                $this->calls++;

                return array_map(fn () => [1.0, 0.0], $texts);
            }

            public function dimensions(): ?int
            {
                return 2;
            }
        };
        $cached = new CachedEmbeddingProvider($provider, new InMemoryEmbeddingCache());

        $cached->embed(['same']);
        $cached->embed(['same']);

        $this->assertSame(1, $provider->calls);
        $this->assertSame([
            'hits' => 1,
            'misses' => 1,
            'saved_calls' => 1,
            'estimated_saved_tokens' => 1,
        ], $cached->stats());
    }

    public function test_retriever_filters_results_below_minimum_score(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        (new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store))
            ->indexDocuments([Document::fromText('Completely unrelated database topic')]);

        $results = (new Retriever($embeddings, $store, minimumScore: 1.0))
            ->retrieve('Laravel agent');

        $this->assertSame([], $results);
    }

    public function test_retriever_tool_marks_zero_results_explicitly(): void
    {
        $tool = new RetrieverTool(new Retriever(
            new FakeEmbeddingProvider(),
            new InMemoryVectorStore(),
            minimumScore: 0.75,
        ));

        $results = $tool->handle(['query' => 'missing']);

        $this->assertFalse($results[0]['found']);
        $this->assertSame([], $results[0]['results']);
    }

    public function test_strict_metadata_schema_rejects_nested_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be scalar or null');

        (new StrictMetadataSchema())->validate(['nested' => ['unsafe' => true]]);
    }

    public function test_vector_store_rejects_non_portable_nested_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be scalar or null');

        (new InMemoryVectorStore())->search([1, 0], filters: ['nested' => ['value' => true]]);
    }

    public function test_streaming_file_loader_preserves_all_content_across_segments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-stream');
        $content = "first line\nsecond line\nthird line\n";
        file_put_contents($path, $content);

        $documents = (new StreamingFileDocumentLoader($path, segmentBytes: 15, maxBytes: 100))->load();

        $this->assertGreaterThan(1, count($documents));
        $this->assertSame($content, implode('', array_map(fn (Document $document) => $document->content, $documents)));
        unlink($path);
    }

    public function test_indexer_consumes_streaming_loader_without_materializing_load(): void
    {
        $loader = new class implements StreamingDocumentLoader {
            public function load(): array
            {
                throw new \RuntimeException('Streaming loader should not be materialized.');
            }

            public function documents(): iterable
            {
                yield Document::fromText('first');
                yield Document::fromText('second');
            }
        };
        $summary = (new RagIndexer(
            new RecursiveCharacterChunker(),
            new FakeEmbeddingProvider(),
            new InMemoryVectorStore(),
        ))->index($loader);

        $this->assertSame(2, $summary['documents']);
        $this->assertSame(2, $summary['chunks']);
    }

    public function test_streaming_index_summary_accumulates_cache_metrics_across_batches(): void
    {
        $documents = array_map(fn (int $index) => Document::fromText("same {$index}"), range(1, 26));
        $loader = new class($documents) implements StreamingDocumentLoader {
            public function __construct(protected array $items)
            {
            }

            public function load(): array
            {
                throw new \RuntimeException('Streaming loader should not be materialized.');
            }

            public function documents(): iterable
            {
                yield from $this->items;
            }
        };
        $embeddings = new CachedEmbeddingProvider(new FakeEmbeddingProvider(), new InMemoryEmbeddingCache());
        $indexer = new RagIndexer(new RecursiveCharacterChunker(), $embeddings, new InMemoryVectorStore());

        $indexer->index($loader);
        $summary = $indexer->index($loader);

        $this->assertSame(26, $summary['embedding_cache']['hits']);
        $this->assertSame(0, $summary['embedding_cache']['misses']);
    }

    public function test_index_job_has_retry_timeout_and_queue_configuration(): void
    {
        $loaderJob = new IndexDocumentLoaderJob(new StringDocumentLoader('content'), queue: 'rag-indexing');
        $this->assertSame(3, $loaderJob->tries);
        $this->assertSame(30, $loaderJob->backoff);
        $this->assertSame(900, $loaderJob->timeout);
        $this->assertSame('rag-indexing', $loaderJob->queue);
        $this->assertNotSame('', $loaderJob->checkpointId);
    }

    public function test_indexing_limits_fail_before_embedding_or_store_writes(): void
    {
        $provider = new class implements EmbeddingProvider {
            public int $calls = 0;

            public function embed(array $texts): array
            {
                $this->calls++;

                return [[1.0]];
            }

            public function dimensions(): ?int
            {
                return 1;
            }
        };
        $indexer = new RagIndexer(
            new RecursiveCharacterChunker(10, 0),
            $provider,
            new InMemoryVectorStore(),
            limits: new IndexingLimits(maxDocumentBytes: 5),
        );

        try {
            $indexer->indexDocuments([Document::fromText('too large')]);
            $this->fail('Expected indexing limit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('byte limit', $exception->getMessage());
            $this->assertSame(0, $provider->calls);
        }
    }

    public function test_zero_result_policies_are_configurable(): void
    {
        $this->assertSame([], (new ZeroResultPolicy('empty_array'))->handle('missing'));
        $this->assertSame(
            [['query' => 'missing']],
            (new ZeroResultPolicy('callback', fn (string $query) => [['query' => $query]]))->handle('missing'),
        );

        $this->expectException(NoRelevantContextException::class);
        (new ZeroResultPolicy('exception'))->handle('missing');
    }

    public function test_rag_index_and_retrieve_create_observability_spans(): void
    {
        $traceStore = new InMemoryTraceStore();
        $traces = new TraceManager($traceStore, new NullTraceExporter(), new CostCalculator());
        $trace = $traces->startTrace('rag-test');
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        $indexer = new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store, traces: $traces);
        $retriever = new Retriever($embeddings, $store, traces: $traces);

        $indexer->indexDocuments([Document::fromText('Laravel agent')]);
        $retriever->retrieve('Laravel agent');
        $traces->finishTrace($trace);

        $stored = $traceStore->get($trace->id);
        $this->assertSame(['rag.index', 'rag.retrieve'], array_map(fn ($span) => $span->name, $stored->spans));
        $this->assertSame(1, $stored->spans[0]->attributes['rag.summary']['documents']);
        $this->assertSame(1, $stored->spans[1]->attributes['rag.results']);
    }

    public function test_checkpoint_store_persists_and_forgets_progress(): void
    {
        $store = new InMemoryIndexingCheckpointStore();

        $this->assertSame(0, $store->nextBatch('job'));
        $store->put('job', 3);
        $this->assertSame(3, $store->nextBatch('job'));
        $store->forget('job');
        $this->assertSame(0, $store->nextBatch('job'));
    }

    public function test_metadata_filters_support_unicode_special_characters_and_null(): void
    {
        $store = new InMemoryVectorStore();
        $embeddings = new FakeEmbeddingProvider();
        (new RagIndexer(new RecursiveCharacterChunker(), $embeddings, $store))
            ->indexDocuments([Document::fromText('Laravel agent', ['label' => 'ação & suporte', 'optional' => null])]);

        $results = (new Retriever($embeddings, $store))->retrieve(
            'Laravel agent',
            filters: ['label' => 'ação & suporte', 'optional' => null],
        );

        $this->assertCount(1, $results);
    }

    public function test_qdrant_uses_is_null_filter_for_null_metadata(): void
    {
        $http = new Factory();
        $http->fake(['*' => ['result' => []]]);
        $store = new QdrantVectorStore('https://qdrant.test', 'docs', http: $http, autoCreateCollection: false);

        $store->search([1, 0], filters: ['optional' => null]);

        $http->assertSent(fn ($request) => $request['filter']['must'][1]['is_null']['key'] === 'metadata.optional');
    }

    public function test_qdrant_replacement_writes_new_points_before_removing_stale_points(): void
    {
        $http = new Factory();
        $http->fakeSequence()
            ->push(['result' => true])
            ->push(['result' => true]);
        $store = new QdrantVectorStore('https://qdrant.test', 'docs', http: $http, autoCreateCollection: false);
        $record = new \Andmarruda\LaravelAgents\RAG\Data\VectorRecord(
            'new-chunk',
            [1, 0],
            'replacement',
            [],
            'document',
        );

        $store->replaceDocument('document', [$record], 'knowledge');

        $requests = $http->recorded();
        $this->assertStringContainsString('/points?wait=true', (string) $requests[0][0]->url());
        $this->assertStringContainsString('/points/delete?wait=true', (string) $requests[1][0]->url());
        $this->assertNotEmpty($requests[1][0]['filter']['must_not'][0]['has_id']);
    }

    public function test_streaming_loader_rejects_oversized_and_binary_content(): void
    {
        $oversized = tempnam(sys_get_temp_dir(), 'rag-large');
        file_put_contents($oversized, str_repeat('x', 20));

        try {
            (new StreamingFileDocumentLoader($oversized, segmentBytes: 5, maxBytes: 10))->load();
            $this->fail('Expected oversized streaming file failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('streaming limit', $exception->getMessage());
        } finally {
            unlink($oversized);
        }

        $binary = tempnam(sys_get_temp_dir(), 'rag-binary');
        file_put_contents($binary, "text\0binary");

        try {
            (new StreamingFileDocumentLoader($binary, segmentBytes: 20, maxBytes: 100))->load();
            $this->fail('Expected binary streaming file failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unsupported binary', $exception->getMessage());
        } finally {
            unlink($binary);
        }
    }

    public function test_index_job_resumes_after_completed_checkpoint_batch(): void
    {
        $embeddings = new class extends FakeEmbeddingProvider {
            public int $embeddedTexts = 0;

            public function embed(array $texts): array
            {
                $this->embeddedTexts += count($texts);

                return parent::embed($texts);
            }
        };
        $indexer = new RagIndexer(new RecursiveCharacterChunker(), $embeddings, new InMemoryVectorStore());
        $manager = new class($indexer) extends LaravelAgentsManager {
            public function __construct(protected RagIndexer $fakeIndexer)
            {
            }

            public function indexer(?string $embeddingModel = null, ?string $vectorStore = null): RagIndexer
            {
                return $this->fakeIndexer;
            }
        };
        $checkpoints = new InMemoryIndexingCheckpointStore();
        $checkpoints->put('resume-job', 1);
        $job = new IndexDocumentLoaderJob(
            new ArrayDocumentLoader(['first', 'second', 'third']),
            batchDocuments: 1,
            checkpointId: 'resume-job',
        );

        $summary = $job->handle($manager, checkpoints: $checkpoints);

        $this->assertSame(2, $summary['documents']);
        $this->assertSame(2, $embeddings->embeddedTexts);
        $this->assertSame(0, $checkpoints->nextBatch('resume-job'));
    }
}
