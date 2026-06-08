<?php

namespace Andmarruda\LaravelAgents\RAG;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use InvalidArgumentException;
use RuntimeException;

class Retriever
{
    public function __construct(
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected int $defaultLimit = 5,
        protected ?string $namespace = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, SearchResult>
     */
    public function retrieve(string $query, ?int $limit = null, array $filters = []): array
    {
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

        return $this->store->search($vector, $limit ?? $this->defaultLimit, $filters, $this->namespace);
    }

    public function inNamespace(?string $namespace): self
    {
        return new self($this->embeddings, $this->store, $this->defaultLimit, $namespace);
    }
}
