<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Adapters\Images\OpenAiImageAdapter;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImageRouterTest extends TestCase
{
    public function test_it_resolves_openai_image_models(): void
    {
        $router = new ImageRouter($this->config());

        $image = $router->for('openai/gpt-image-1');

        $this->assertInstanceOf(OpenAiImageAdapter::class, $image);
        $this->assertSame('image.generate', $image->capability());
    }

    public function test_it_uses_default_image_model_from_capabilities(): void
    {
        $router = new ImageRouter($this->config([
            'capabilities' => [
                'image' => [
                    'default_model' => 'openai/gpt-image-1',
                ],
            ],
        ]));

        $this->assertInstanceOf(OpenAiImageAdapter::class, $router->for());
    }

    public function test_it_rejects_invalid_image_model_names(): void
    {
        $router = new ImageRouter($this->config());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider/model');

        $router->for('gpt-image-1');
    }

    public function test_it_rejects_unsupported_image_providers(): void
    {
        $router = new ImageRouter($this->config());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image provider [unknown].');

        $router->for('unknown/model');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function config(array $overrides = []): array
    {
        return [
            'models' => [
                'timeout' => 60,
                'retry_times' => 0,
                'retry_sleep' => 0,
            ],
            'providers' => [
                'openai' => [
                    'api_key' => 'test',
                    'base_url' => 'https://example.test/openai',
                ],
            ],
            ...$overrides,
        ];
    }
}
