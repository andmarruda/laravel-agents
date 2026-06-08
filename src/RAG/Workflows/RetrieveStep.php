<?php

namespace Andmarruda\LaravelAgents\RAG\Workflows;

use Andmarruda\LaravelAgents\RAG\Retriever;
use Andmarruda\LaravelAgents\Workflows\Step;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;
use InvalidArgumentException;

class RetrieveStep implements Step
{
    public function __construct(
        protected Retriever $retriever,
    ) {
    }

    public function handle(mixed $input, WorkflowContext $context): array
    {
        $query = is_string($input) ? $input : ($input['query'] ?? null);

        if (! is_string($query)) {
            throw new InvalidArgumentException('RetrieveStep input must be a query string or contain [query].');
        }

        $limit = is_array($input) && isset($input['limit']) ? (int) $input['limit'] : null;
        $filters = is_array($input) && is_array($input['filters'] ?? null) ? $input['filters'] : [];

        return array_map(fn ($result) => $result->toArray(), $this->retriever->retrieve($query, $limit, $filters));
    }
}
