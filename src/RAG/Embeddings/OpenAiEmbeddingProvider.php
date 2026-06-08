<?php

namespace Andmarruda\LaravelAgents\RAG\Embeddings;

use Andmarruda\LaravelAgents\RAG\Contracts\EmbeddingProvider;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $model,
        protected array $config,
        protected ?Factory $http = null,
        protected int $batchSize = 100,
        protected ?int $configuredDimensions = null,
        protected int $timeout = 60,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Embedding batch size must be at least 1.');
        }

        if ($configuredDimensions !== null && $configuredDimensions < 1) {
            throw new InvalidArgumentException('Embedding dimensions must be at least 1.');
        }
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        foreach ($texts as $text) {
            if (! is_string($text)) {
                throw new InvalidArgumentException('Embedding inputs must be strings.');
            }
        }

        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $http = $this->http ?? app(Factory::class);
        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $payload = [
                'model' => $this->model,
                'input' => array_values($batch),
            ];

            if ($this->configuredDimensions !== null) {
                $payload['dimensions'] = $this->configuredDimensions;
            }

            $response = $http->timeout($this->timeout)
                ->withToken($apiKey)
                ->post(rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'), '/').'/embeddings', $payload)
                ->throw()
                ->json();
            $data = $response['data'] ?? [];
            usort($data, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            foreach ($data as $item) {
                $vector = $item['embedding'] ?? null;

                if (! is_array($vector)) {
                    throw new RuntimeException('Embedding provider returned an invalid vector.');
                }

                $vectors[] = array_map('floatval', $vector);
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Embedding provider returned a different number of vectors than inputs.');
        }

        $this->assertDimensions($vectors);

        return $vectors;
    }

    public function dimensions(): ?int
    {
        return $this->configuredDimensions;
    }

    /**
     * @param array<int, array<int, float>> $vectors
     */
    protected function assertDimensions(array $vectors): void
    {
        $expected = $this->configuredDimensions ?? count($vectors[0]);

        foreach ($vectors as $vector) {
            if (count($vector) !== $expected) {
                throw new RuntimeException("Embedding vector dimensions must equal {$expected}.");
            }
        }
    }
}
