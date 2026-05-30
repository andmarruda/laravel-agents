<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Adapters\Images\OpenAiImageAdapter;
use Andmarruda\LaravelAgents\Adapters\Models\OpenAiModelAdapter;
use Andmarruda\LaravelAgents\Images\ImageRouter;
use Andmarruda\LaravelAgents\Kernel\AgentKernel;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use PHPUnit\Framework\TestCase;

class KernelTest extends TestCase
{
    public function test_kernel_routes_text_and_image_capabilities(): void
    {
        $config = [
            'capabilities' => [
                'text' => ['default_model' => 'openai/gpt-4.1-mini'],
                'image' => ['default_model' => 'openai/gpt-image-1'],
            ],
            'models' => ['timeout' => 60, 'retry_times' => 0, 'retry_sleep' => 0],
            'providers' => [
                'openai' => [
                    'api_key' => 'test',
                    'base_url' => 'https://example.test/openai',
                ],
            ],
        ];

        $kernel = new AgentKernel(new ModelRouter($config), new ImageRouter($config));

        $this->assertInstanceOf(OpenAiModelAdapter::class, $kernel->text());
        $this->assertInstanceOf(OpenAiImageAdapter::class, $kernel->image());
    }
}
