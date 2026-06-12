<?php

require __DIR__.'/../vendor/autoload.php';

use Andmarruda\LaravelAgents\RAG\Chunking\SemanticTextChunker;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Embeddings\CachedEmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Embeddings\InMemoryEmbeddingCache;
use Andmarruda\LaravelAgents\RAG\RagIndexer;
use Andmarruda\LaravelAgents\RAG\Retriever;
use Andmarruda\LaravelAgents\RAG\VectorStores\InMemoryVectorStore;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeEmbeddingProvider;

$documents = array_map(
    fn (int $index) => Document::fromText(str_repeat("Laravel agent document {$index}. ", 100), source: "benchmark:{$index}"),
    range(1, 100),
);
$embeddings = new CachedEmbeddingProvider(new FakeEmbeddingProvider(), new InMemoryEmbeddingCache());
$store = new InMemoryVectorStore();
$indexer = new RagIndexer(new SemanticTextChunker(), $embeddings, $store);

$run = function (callable $operation): array {
    $memory = memory_get_usage(true);
    $started = hrtime(true);
    $result = $operation();

    return [
        'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        'memory_delta_bytes' => memory_get_usage(true) - $memory,
        'result' => $result,
    ];
};

$first = $run(fn () => $indexer->indexDocuments($documents, 'benchmark'));
$cached = $run(fn () => $indexer->indexDocuments($documents, 'benchmark'));
$retrieval = $run(fn () => count((new Retriever($embeddings, $store, namespace: 'benchmark'))->retrieve('Laravel agent')));

echo json_encode([
    'first_index' => $first,
    'cached_index' => $cached,
    'retrieval' => $retrieval,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
