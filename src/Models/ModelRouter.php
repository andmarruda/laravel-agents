<?php

namespace Andmarruda\LaravelAgents\Models;

use InvalidArgumentException;
use Andmarruda\LaravelAgents\Adapters\Models\AnthropicModelAdapter;
use Andmarruda\LaravelAgents\Adapters\Models\FireworksModelAdapter;
use Andmarruda\LaravelAgents\Adapters\Models\OpenAiModelAdapter;
use Andmarruda\LaravelAgents\Ports\ModelPort;

class ModelRouter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
    ) {
    }

    public function for(?string $model = null): ModelPort
    {
        $model ??= $this->config['default_model'] ?? 'openai/gpt-4.1-mini';

        [$provider, $modelName] = $this->parseModel($model);

        return match ($provider) {
            'openai' => new OpenAiModelAdapter($modelName, $this->providerConfig('openai'), $this->modelConfig()),
            'anthropic', 'claude' => new AnthropicModelAdapter($modelName, $this->providerConfig('anthropic'), $this->modelConfig()),
            'fireworks' => new FireworksModelAdapter($modelName, $this->providerConfig('fireworks'), $this->modelConfig()),
            default => throw new InvalidArgumentException("Unsupported model provider [{$provider}]."),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModel(string $model): array
    {
        if (! str_contains($model, '/')) {
            throw new InvalidArgumentException('Model names must use the provider/model format.');
        }

        [$provider, $modelName] = explode('/', $model, 2);

        return [$provider, $modelName];
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerConfig(string $provider): array
    {
        return $this->config['providers'][$provider] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function modelConfig(): array
    {
        return $this->config['models'] ?? [];
    }
}
