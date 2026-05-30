<?php

namespace Andmarruda\LaravelAgents\Data;

class ImageGenerationRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $model = 'gpt-image-1',
        public readonly string $size = '1024x1024',
        public readonly int $n = 1,
        public readonly ?string $quality = null,
        public readonly array $metadata = [],
    ) {
    }
}
