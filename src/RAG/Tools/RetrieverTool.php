<?php

namespace Andmarruda\LaravelAgents\RAG\Tools;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\RAG\Retriever;
use InvalidArgumentException;

class RetrieverTool implements Tool
{
    public function __construct(
        protected Retriever $retriever,
        protected string $toolName = 'retrieve_context',
        protected string $toolDescription = 'Retrieve relevant context from the configured knowledge base.',
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1],
                'filters' => ['type' => 'object'],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(array $input): array
    {
        if (! is_string($input['query'] ?? null)) {
            throw new InvalidArgumentException('Retriever tool requires a string [query].');
        }

        $results = $this->retriever->retrieve(
            $input['query'],
            isset($input['limit']) ? (int) $input['limit'] : null,
            is_array($input['filters'] ?? null) ? $input['filters'] : [],
        );

        return array_map(fn ($result) => $result->toArray(), $results);
    }
}
