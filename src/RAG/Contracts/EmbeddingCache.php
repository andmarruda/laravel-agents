<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

interface EmbeddingCache
{
    /**
     * @return array<int, float>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<int, float> $vector
     */
    public function put(string $key, array $vector): void;
}
