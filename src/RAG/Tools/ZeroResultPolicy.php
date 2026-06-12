<?php

namespace Andmarruda\LaravelAgents\RAG\Tools;

use Andmarruda\LaravelAgents\RAG\Exceptions\NoRelevantContextException;
use InvalidArgumentException;

class ZeroResultPolicy
{
    /**
     * @param callable(string): array<int, mixed>|null $callback
     */
    public function __construct(
        protected string $policy = 'explicit_empty',
        protected mixed $callback = null,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function handle(string $query): array
    {
        return match ($this->policy) {
            'explicit_empty' => [[
                'found' => false,
                'message' => 'No relevant context was found for this query.',
                'results' => [],
            ]],
            'empty_array' => [],
            'exception' => throw new NoRelevantContextException('No relevant context was found for this query.'),
            'callback' => is_callable($this->callback)
                ? ($this->callback)($query)
                : throw new InvalidArgumentException('RAG zero-result callback policy requires a callable.'),
            default => throw new InvalidArgumentException("Unsupported RAG zero-result policy [{$this->policy}]."),
        };
    }
}
