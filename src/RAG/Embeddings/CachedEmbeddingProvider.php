<?php

namespace Andmarruda\LaravelAgents\RAG\Embeddings;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingCache;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\NamedEmbeddingProvider;
use RuntimeException;

class CachedEmbeddingProvider implements EmbeddingProvider
{
    protected int $hits = 0;

    protected int $misses = 0;

    protected int $savedTextBytes = 0;

    public function __construct(
        protected EmbeddingProvider $provider,
        protected EmbeddingCache $cache,
        protected string $version = '1',
    ) {
    }

    public function embed(array $texts): array
    {
        $vectors = [];
        $missing = [];

        foreach ($texts as $index => $text) {
            $key = $this->key($text);
            $cached = $this->cache->get($key);

            if ($cached !== null) {
                $vectors[$index] = $cached;
                $this->hits++;
                $this->savedTextBytes += strlen($text);
            } else {
                $missing[$index] = $text;
                $this->misses++;
            }
        }

        if ($missing !== []) {
            $embedded = $this->provider->embed(array_values($missing));

            foreach (array_keys($missing) as $offset => $index) {
                $vector = $embedded[$offset] ?? null;
                if (! is_array($vector) || $vector === []) {
                    throw new RuntimeException('Embedding provider returned an empty or missing cached vector.');
                }

                $vectors[$index] = $vector;
                $this->cache->put($this->key($texts[$index]), $vector);
            }
        }

        ksort($vectors);

        return array_values($vectors);
    }

    public function dimensions(): ?int
    {
        return $this->provider->dimensions();
    }

    /**
     * @return array{hits: int, misses: int, saved_calls: int, estimated_saved_tokens: int}
     */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'saved_calls' => $this->hits,
            'estimated_saved_tokens' => (int) ceil($this->savedTextBytes / 4),
        ];
    }

    protected function key(string $text): string
    {
        $namespace = $this->provider instanceof NamedEmbeddingProvider
            ? $this->provider->cacheNamespace()
            : $this->provider::class.':'.($this->provider->dimensions() ?? 'auto');

        return hash('sha256', $namespace."\0".$this->version."\0".$text);
    }
}
