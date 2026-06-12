<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

interface IndexingCheckpointStore
{
    public function nextBatch(string $id): int;

    public function put(string $id, int $nextBatch): void;

    public function forget(string $id): void;
}
