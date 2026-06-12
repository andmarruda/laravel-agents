<?php

namespace Andmarruda\LaravelAgents\RAG;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use InvalidArgumentException;
use RuntimeException;
use Andmarruda\LaravelAgents\Observability\TraceManager;

class Retriever
{
    public function __construct(
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected int $defaultLimit = 5,
        protected ?string $namespace = null,
        protected ?float $minimumScore = null,
        protected ?TraceManager $traces = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, SearchResult>
     */
    public function retrieve(string $query, ?int $limit = null, array $filters = [], ?float $minimumScore = null): array
    {
        $span = $this->traces?->startSpan('rag.retrieve', 'internal', [
            'rag.namespace' => $this->namespace,
            'rag.limit' => $limit ?? $this->defaultLimit,
        ]);

        try {
        if (trim($query) === '') {
            throw new InvalidArgumentException('Retrieval query cannot be empty.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Retrieval limit must be at least 1.');
        }

        $vector = $this->embeddings->embed([$query])[0] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('Embedding provider did not return a query vector.');
        }

        $minimumScore ??= $this->minimumScore;
        if ($minimumScore !== null && ($minimumScore < -1 || $minimumScore > 1)) {
            throw new InvalidArgumentException('Retrieval minimum score must be between -1 and 1.');
        }

        $results = $this->store->search($vector, $limit ?? $this->defaultLimit, $filters, $this->namespace);

        $filtered = $minimumScore === null
            ? $results
            : array_values(array_filter($results, fn (SearchResult $result) => $result->score >= $minimumScore));
        $this->traces?->finishSpan($span, [
            'rag.results' => count($filtered),
            'rag.minimum_score' => $minimumScore,
        ]);

        return $filtered;
        } catch (\Throwable $exception) {
            $this->traces?->failSpan($span, $exception);
            throw $exception;
        }
    }

    public function inNamespace(?string $namespace): self
    {
        return new self($this->embeddings, $this->store, $this->defaultLimit, $namespace, $this->minimumScore, $this->traces);
    }
}
