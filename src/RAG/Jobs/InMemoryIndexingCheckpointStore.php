<?php

namespace Andmarruda\LaravelAgents\RAG\Jobs;

use Andmarruda\LaravelAgents\RAG\Contracts\IndexingCheckpointStore;

class InMemoryIndexingCheckpointStore implements IndexingCheckpointStore
{
    /**
     * @var array<string, int>
     */
    protected array $checkpoints = [];

    public function nextBatch(string $id): int
    {
        return $this->checkpoints[$id] ?? 0;
    }

    public function put(string $id, int $nextBatch): void
    {
        $this->checkpoints[$id] = max(0, $nextBatch);
    }

    public function forget(string $id): void
    {
        unset($this->checkpoints[$id]);
    }
}
