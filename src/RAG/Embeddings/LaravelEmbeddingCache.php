<?php

namespace Andmarruda\LaravelAgents\RAG\Embeddings;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingCache;
use Illuminate\Contracts\Cache\Repository;

class LaravelEmbeddingCache implements EmbeddingCache
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'agents:rag:embeddings:',
        protected ?int $ttl = null,
    ) {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->prefix.$key);

        return is_array($value) ? array_map('floatval', $value) : null;
    }

    public function put(string $key, array $vector): void
    {
        $this->cache->put($this->prefix.$key, array_map('floatval', $vector), $this->ttl);
    }
}
