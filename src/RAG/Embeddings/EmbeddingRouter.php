<?php

namespace Andmarruda\LaravelAgents\RAG\Embeddings;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingCache;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

class EmbeddingRouter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
        protected ?Factory $http = null,
        protected ?EmbeddingCache $cache = null,
    ) {
    }

    public function for(?string $model = null): EmbeddingProvider
    {
        $model ??= $this->config['rag']['embeddings']['default_model'] ?? 'openai/text-embedding-3-small';

        if (! str_contains($model, '/')) {
            throw new InvalidArgumentException('Embedding model names must use the provider/model format.');
        }

        [$provider, $modelName] = explode('/', $model, 2);
        $settings = $this->config['rag']['embeddings'] ?? [];

        $embeddingProvider = match ($provider) {
            'openai' => new OpenAiEmbeddingProvider(
                model: $modelName,
                config: $this->config['providers']['openai'] ?? [],
                http: $this->http,
                batchSize: (int) ($settings['batch_size'] ?? 100),
                configuredDimensions: isset($settings['dimensions']) ? (int) $settings['dimensions'] : null,
                timeout: (int) ($settings['timeout'] ?? 60),
            ),
            default => throw new InvalidArgumentException("Unsupported embedding provider [{$provider}]."),
        };

        return $this->cache && ($settings['cache']['enabled'] ?? true)
            ? new CachedEmbeddingProvider($embeddingProvider, $this->cache, (string) ($settings['cache']['version'] ?? '1'))
            : $embeddingProvider;
    }
}
