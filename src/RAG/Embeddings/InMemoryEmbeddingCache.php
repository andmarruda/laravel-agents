<?php

namespace Andmarruda\LaravelAgents\RAG\Embeddings;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingCache;

class InMemoryEmbeddingCache implements EmbeddingCache
{
    /**
     * @var array<string, array<int, float>>
     */
    protected array $vectors = [];

    public function get(string $key): ?array
    {
        return $this->vectors[$key] ?? null;
    }

    public function put(string $key, array $vector): void
    {
        $this->vectors[$key] = array_map('floatval', $vector);
    }
}
