<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Adapters\Models\AnthropicModelAdapter;
use Andmarruda\LaravelAgents\Adapters\Models\FireworksModelAdapter;
use Andmarruda\LaravelAgents\Adapters\Models\OpenAiModelAdapter;
use Andmarruda\LaravelAgents\Models\ModelRouter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModelRouterTest extends TestCase
{
    public function test_it_resolves_openai_models(): void
    {
        $router = new ModelRouter($this->config());

        $this->assertInstanceOf(OpenAiModelAdapter::class, $router->for('openai/gpt-4.1-mini'));
    }

    public function test_it_resolves_anthropic_models(): void
    {
        $router = new ModelRouter($this->config());

        $this->assertInstanceOf(AnthropicModelAdapter::class, $router->for('anthropic/claude-sonnet-4'));
        $this->assertInstanceOf(AnthropicModelAdapter::class, $router->for('claude/claude-sonnet-4'));
    }

    public function test_it_resolves_fireworks_models(): void
    {
        $router = new ModelRouter($this->config());

        $this->assertInstanceOf(FireworksModelAdapter::class, $router->for('fireworks/accounts/fireworks/models/test'));
    }

    public function test_it_uses_default_model_when_model_is_missing(): void
    {
        $router = new ModelRouter($this->config([
            'default_model' => 'openai/default-model',
        ]));

        $this->assertInstanceOf(OpenAiModelAdapter::class, $router->for());
    }

    public function test_it_rejects_model_names_without_provider_prefix(): void
    {
        $router = new ModelRouter($this->config());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider/model');

        $router->for('gpt-4.1-mini');
    }

    public function test_it_rejects_unsupported_providers(): void
    {
        $router = new ModelRouter($this->config());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported model provider [unknown].');

        $router->for('unknown/model');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function config(array $overrides = []): array
    {
        return [
            'default_model' => 'openai/gpt-4.1-mini',
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
                'anthropic' => [
                    'api_key' => 'test',
                    'base_url' => 'https://example.test/anthropic',
                    'version' => '2023-06-01',
                ],
                'fireworks' => [
                    'api_key' => 'test',
                    'base_url' => 'https://example.test/fireworks',
                ],
            ],
            ...$overrides,
        ];
    }
}
