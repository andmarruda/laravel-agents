<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

interface NamedEmbeddingProvider extends EmbeddingProvider
{
    public function cacheNamespace(): string;
}
