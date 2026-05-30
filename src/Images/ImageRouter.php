<?php

namespace Andmarruda\LaravelAgents\Images;

use Andmarruda\LaravelAgents\Adapters\Images\OpenAiImageAdapter;
use Andmarruda\LaravelAgents\Ports\ImageGenerationPort;
use InvalidArgumentException;

class ImageRouter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
    ) {
    }

    public function for(?string $model = null): ImageGenerationPort
    {
        $model ??= $this->config['capabilities']['image']['default_model']
            ?? $this->config['images']['default_model']
            ?? 'openai/gpt-image-1';

        [$provider, $modelName] = $this->parseModel($model);

        return match ($provider) {
            'openai' => new OpenAiImageAdapter($modelName, $this->providerConfig('openai'), $this->runtimeConfig()),
            default => throw new InvalidArgumentException("Unsupported image provider [{$provider}]."),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseModel(string $model): array
    {
        if (! str_contains($model, '/')) {
            throw new InvalidArgumentException('Image model names must use the provider/model format.');
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
    protected function runtimeConfig(): array
    {
        return $this->config['models'] ?? [];
    }
}
