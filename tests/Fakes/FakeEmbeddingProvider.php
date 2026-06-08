<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;

class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts): array
    {
        return array_map(function (string $text): array {
            $lower = strtolower($text);

            return [
                (float) substr_count($lower, 'laravel'),
                (float) substr_count($lower, 'agent'),
                (float) substr_count($lower, 'database'),
                (float) max(1, strlen($text) / 100),
            ];
        }, $texts);
    }

    public function dimensions(): ?int
    {
        return 4;
    }
}
