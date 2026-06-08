<?php

namespace Andmarruda\LaravelAgents\Observability\Events;

class ModelCallStarted
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly ?string $model,
        public readonly array $messages,
        public readonly array $options = [],
    ) {
    }
}
