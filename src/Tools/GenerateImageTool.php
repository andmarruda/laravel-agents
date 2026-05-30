<?php

namespace Andmarruda\LaravelAgents\Tools;

use Andmarruda\LaravelAgents\Contracts\Tool;
use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Images\ImageRouter;

class GenerateImageTool implements Tool
{
    public function name(): string
    {
        return 'generate_image';
    }

    public function description(): string
    {
        return 'Generate an image from a prompt using the configured image provider.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string'],
                'size' => ['type' => 'string'],
                'quality' => ['type' => 'string'],
                'variations' => ['type' => 'integer'],
            ],
            'required' => ['prompt'],
        ];
    }

    public function handle(array $input): mixed
    {
        return app(ImageRouter::class)
            ->for(config('agents.capabilities.image.default_model'))
            ->generate(new ImageGenerationRequest(
                prompt: $input['prompt'],
                size: $input['size'] ?? config('agents.capabilities.image.default_size', '1024x1024'),
                n: $input['variations'] ?? 1,
                quality: $input['quality'] ?? null,
                metadata: $input['metadata'] ?? [],
            ));
    }
}
