<?php

namespace Andmarruda\LaravelAgents\Adapters\Models;

use RuntimeException;
use Andmarruda\LaravelAgents\Adapters\Models\Concerns\BuildsHttpClient;
use Andmarruda\LaravelAgents\Data\ModelResponse;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class AnthropicModelAdapter implements ModelPort
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
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        [$system, $chatMessages] = $this->splitSystemMessages($messages);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $chatMessages,
            ...$this->payloadOptions($options),
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        $response = $this->client($options)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $this->config['version'] ?? '2023-06-01',
            ])
            ->post(rtrim($this->config['base_url'], '/').'/messages', $payload)
            ->throw()
            ->json();

        return new ModelResponse(
            content: $response['content'][0]['text'] ?? '',
            model: $this->model,
            provider: 'anthropic',
            usage: $response['usage'] ?? [],
            raw: $response,
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{0: string, 1: array<int, array{role: string, content: string}>}
     */
    protected function splitSystemMessages(array $messages): array
    {
        $system = [];
        $chat = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                $system[] = $message['content'] ?? '';

                continue;
            }

            $chat[] = $message;
        }

        return [implode("\n\n", $system), $chat];
    }
}
