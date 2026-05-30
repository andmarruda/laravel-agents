<?php

namespace Andmarruda\LaravelAgents\Tests\Fakes;

use Andmarruda\LaravelAgents\Data\ModelResponse;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class FakeModelPort implements ModelPort
{
    /**
     * @var array<int, array<int, array{role: string, content: string}>>
     */
    public array $messages = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param array<int, string> $responses
     */
    public function __construct(
        protected array $responses = [],
    ) {
    }

    public function capability(): string
    {
        return 'text.generate';
    }

    public function generate(array $messages, array $options = []): ModelResponse
    {
        $this->messages[] = $messages;
        $this->options[] = $options;

        return new ModelResponse(
            content: array_shift($this->responses) ?? '',
            model: 'fake-model',
            provider: 'fake',
            usage: ['total_tokens' => 1],
            raw: ['fake' => true],
        );
    }
}
