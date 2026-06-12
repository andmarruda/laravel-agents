<?php

namespace Andmarruda\LaravelAgents\RAG\Jobs;

use Andmarruda\LaravelAgents\LaravelAgentsManager;
use Andmarruda\LaravelAgents\Observability\Support\LaravelEventDispatcher;
use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Contracts\StreamingDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Contracts\IndexingCheckpointStore;
use Andmarruda\LaravelAgents\RAG\Events\DocumentIndexingCompleted;
use Andmarruda\LaravelAgents\RAG\Events\DocumentIndexingFailed;
use Andmarruda\LaravelAgents\RAG\Events\DocumentIndexingProgressed;
use Andmarruda\LaravelAgents\RAG\Events\DocumentIndexingStarted;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use Throwable;

class IndexDocumentLoaderJob implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 900;

    public ?string $queue = null;

    public readonly string $checkpointId;

    public function __construct(
        public readonly DocumentLoader $loader,
        public readonly ?string $namespace = null,
        public readonly ?string $embeddingModel = null,
        public readonly ?string $vectorStore = null,
        public readonly int $batchDocuments = 25,
        ?string $queue = null,
        ?string $checkpointId = null,
    ) {
        if ($batchDocuments < 1) {
            throw new InvalidArgumentException('RAG asynchronous batch size must be at least 1.');
        }

        $this->queue = $queue;
        $this->checkpointId = $checkpointId ?? bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(
        LaravelAgentsManager $manager,
        ?LaravelEventDispatcher $events = null,
        ?IndexingCheckpointStore $checkpoints = null,
    ): array
    {
        $events ??= new LaravelEventDispatcher();

        try {
            $documents = $this->loader instanceof StreamingDocumentLoader
                ? $this->loader->documents()
                : $this->loader->load();
            $batches = $this->batches($documents);
            $knownDocuments = is_array($documents) ? count($documents) : 0;
            $knownBatches = is_array($documents) ? (int) ceil(count($documents) / $this->batchDocuments) : 0;
            $resumeFrom = $checkpoints?->nextBatch($this->checkpointId) ?? 0;
            $events->dispatch(new DocumentIndexingStarted($knownDocuments, $this->namespace));
            $summary = [
                'documents' => 0,
                'chunks' => 0,
                'namespace' => $this->namespace,
                'batches' => $knownBatches,
            ];

            foreach ($batches as $index => $batch) {
                if ($index < $resumeFrom) {
                    continue;
                }

                $result = $manager->indexer($this->embeddingModel, $this->vectorStore)
                    ->indexDocuments($batch, $this->namespace);
                $summary['documents'] += $result['documents'];
                $summary['chunks'] += $result['chunks'];
                foreach ($result['embedding_cache'] ?? [] as $key => $value) {
                    $summary['embedding_cache'][$key] = ($summary['embedding_cache'][$key] ?? 0) + $value;
                }
                $events->dispatch(new DocumentIndexingProgressed(
                    $index + 1,
                    $knownBatches,
                    count($batch),
                    $this->namespace,
                ));
                $checkpoints?->put($this->checkpointId, $index + 1);
            }

            $summary['batches'] = $index ?? -1;
            $summary['batches']++;
            $checkpoints?->forget($this->checkpointId);
            $events->dispatch(new DocumentIndexingCompleted($summary));

            return $summary;
        } catch (Throwable $exception) {
            $events->dispatch(new DocumentIndexingFailed($exception, $this->namespace));
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        (new LaravelEventDispatcher())->dispatch(new DocumentIndexingFailed($exception, $this->namespace));
    }

    /**
     * @param iterable<int, \Andmarruda\LaravelAgents\RAG\Data\Document> $documents
     * @return iterable<int, array<int, \Andmarruda\LaravelAgents\RAG\Data\Document>>
     */
    protected function batches(iterable $documents): iterable
    {
        $batch = [];

        foreach ($documents as $document) {
            $batch[] = $document;
            if (count($batch) === $this->batchDocuments) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }
}
