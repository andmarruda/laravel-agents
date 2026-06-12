<?php

namespace Andmarruda\LaravelAgents\RAG\Contracts;

interface MetadataSchema
{
    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function validate(array $metadata): array;
}
