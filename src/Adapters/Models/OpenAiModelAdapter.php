<?php

namespace Andmarruda\LaravelAgents\Adapters\Models;

use RuntimeException;
use Andmarruda\LaravelAgents\Adapters\Models\Concerns\BuildsHttpClient;
use Andmarruda\LaravelAgents\Data\ModelResponse;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class OpenAiModelAdapter implements ModelPort
{
    use BuildsHttpClient;

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $runtime
     */
    public function __construct(
        protected string $model,
        protected array $config,
        protected array $runtime = [],
    ) {
    }

    public function generate(array $messages, array $options = []): ModelResponse
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = $this->client($options)
            ->withToken($apiKey)
            ->post(rtrim($this->config['base_url'], '/').'/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                ...$this->payloadOptions($options),
            ])
            ->throw()
            ->json();

        return new ModelResponse(
            content: $response['choices'][0]['message']['content'] ?? '',
            model: $this->model,
            provider: 'openai',
            usage: $response['usage'] ?? [],
            raw: $response,
        );
    }
}
