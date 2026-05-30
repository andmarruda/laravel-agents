<?php

namespace Andmarruda\LaravelAgents\Data;

class ImageGenerationResponse
{
    /**
     * @param array<int, array<string, mixed>> $images
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $images,
        public readonly string $model,
        public readonly string $provider,
        public readonly array $raw = [],
    ) {
    }

    public function firstUrl(): ?string
    {
        return $this->images[0]['url'] ?? null;
    }

    public function firstBase64(): ?string
    {
        return $this->images[0]['b64_json'] ?? null;
    }
}
