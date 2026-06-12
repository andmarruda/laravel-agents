<?php

namespace Andmarruda\LaravelAgents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Andmarruda\LaravelAgents\Kernel\AgentKernel kernel()
 * @method static \Andmarruda\LaravelAgents\Ports\ModelPort model(string $model)
 * @method static \Andmarruda\LaravelAgents\Ports\ImageGenerationPort image(?string $model = null)
 * @method static \Andmarruda\LaravelAgents\Agents\Agent agent(string|\Andmarruda\LaravelAgents\Agents\Agent $agent)
 * @method static \Andmarruda\LaravelAgents\Workflows\Workflow workflow(string|\Andmarruda\LaravelAgents\Workflows\Workflow|null $workflow = null)
 * @method static \Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider embeddings(?string $model = null)
 * @method static \Andmarruda\LaravelAgents\RAG\Contracts\VectorStore vectorStore(?string $driver = null)
 * @method static \Andmarruda\LaravelAgents\RAG\RagIndexer indexer(?string $embeddingModel = null, ?string $vectorStore = null)
 * @method static \Andmarruda\LaravelAgents\RAG\Retriever retriever(?string $embeddingModel = null, ?string $vectorStore = null, ?string $namespace = null)
 * @method static mixed indexAsync(\Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader $loader, ?string $namespace = null, ?string $embeddingModel = null, ?string $vectorStore = null, ?string $queue = null, int $batchDocuments = 25)
 */
class LaravelAgents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Andmarruda\LaravelAgents\LaravelAgentsManager::class;
    }
}
