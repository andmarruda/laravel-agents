<?php

namespace Andmarruda\LaravelAgents\Adapters\Images;

use Andmarruda\LaravelAgents\Adapters\Models\Concerns\BuildsHttpClient;
use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Data\ImageGenerationResponse;
use Andmarruda\LaravelAgents\Ports\ImageGenerationPort;
use RuntimeException;

class OpenAiImageAdapter implements ImageGenerationPort
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

    public function capability(): string
    {
        return 'image.generate';
    }

    public function generate(ImageGenerationRequest $request): ImageGenerationResponse
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = array_filter([
            'model' => $request->model ?: $this->model,
            'prompt' => $request->prompt,
            'size' => $request->size,
            'n' => $request->n,
            'quality' => $request->quality,
        ], fn (mixed $value) => $value !== null);

        $response = $this->client()
            ->withToken($apiKey)
            ->post(rtrim($this->config['base_url'], '/').'/images/generations', $payload)
            ->throw()
            ->json();

        return new ImageGenerationResponse(
            images: $response['data'] ?? [],
            model: $payload['model'],
            provider: 'openai',
            raw: $response,
        );
    }
}
