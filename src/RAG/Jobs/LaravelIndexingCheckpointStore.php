<?php

namespace Andmarruda\LaravelAgents\RAG\Jobs;

use Andmarruda\LaravelAgents\RAG\Contracts\IndexingCheckpointStore;
use Illuminate\Contracts\Cache\Repository;

class LaravelIndexingCheckpointStore implements IndexingCheckpointStore
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'agents:rag:indexing:',
        protected int $ttl = 86400,
    ) {
    }

    public function nextBatch(string $id): int
    {
        return max(0, (int) $this->cache->get($this->prefix.$id, 0));
    }

    public function put(string $id, int $nextBatch): void
    {
        $this->cache->put($this->prefix.$id, $nextBatch, $this->ttl);
    }

    public function forget(string $id): void
    {
        $this->cache->forget($this->prefix.$id);
    }
}
