<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

interface EmbeddingProvider
{
    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;

    public function dimensions(): ?int;
}
