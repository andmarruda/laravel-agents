<?php

namespace Andmarruda\LaravelAgents\RAG\VectorStores;

use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class VectorStoreRouter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
        protected Container $container,
    ) {
    }

    public function for(?string $driver = null): VectorStore
    {
        $driver ??= $this->config['rag']['vector_store']['default'] ?? 'memory';
        $settings = $this->config['rag']['vector_store']['stores'][$driver] ?? [];

        return match ($driver) {
            'memory' => $this->container->make(InMemoryVectorStore::class),
            'pgvector' => new PgVectorStore(
                $this->container->make('db')->connection($settings['connection'] ?? null),
                $settings['table'] ?? 'agent_rag_vectors',
            ),
            'qdrant' => new QdrantVectorStore(
                baseUrl: (string) ($settings['base_url'] ?? 'http://localhost:6333'),
                collection: (string) ($settings['collection'] ?? 'laravel_agents'),
                apiKey: $settings['api_key'] ?? null,
                http: $this->container->make(\Illuminate\Http\Client\Factory::class),
                timeout: (int) ($settings['timeout'] ?? 60),
                autoCreateCollection: (bool) ($settings['auto_create_collection'] ?? true),
            ),
            default => $this->custom($driver),
        };
    }

    protected function custom(string $driver): VectorStore
    {
        $class = $this->config['rag']['vector_store']['stores'][$driver]['class'] ?? null;

        if (! is_string($class)) {
            throw new InvalidArgumentException("Unsupported vector store [{$driver}].");
        }

        $store = $this->container->make($class);

        if (! $store instanceof VectorStore) {
            throw new InvalidArgumentException("Vector store [{$driver}] must implement VectorStore.");
        }

        return $store;
    }
}
