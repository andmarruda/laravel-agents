<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Data\AgentResponse;
use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Data\ImageGenerationResponse;
use Andmarruda\LaravelAgents\Data\ModelResponse;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    public function test_model_response_decodes_json_content(): void
    {
        $response = new ModelResponse(
            content: '{"answer":"ok"}',
            model: 'model',
            provider: 'provider',
        );

        $this->assertSame(['answer' => 'ok'], $response->json());
    }

    public function test_model_response_returns_null_for_invalid_json(): void
    {
        $response = new ModelResponse(
            content: 'not json',
            model: 'model',
            provider: 'provider',
        );

        $this->assertNull($response->json());
    }

    public function test_agent_response_keeps_content_steps_and_meta(): void
    {
        $response = new AgentResponse(
            content: 'done',
            steps: [['agent' => 'researcher']],
            meta: ['provider' => 'fake'],
        );

        $this->assertSame('done', $response->content);
        $this->assertSame([['agent' => 'researcher']], $response->steps);
        $this->assertSame(['provider' => 'fake'], $response->meta);
    }

    public function test_image_generation_request_keeps_generation_options(): void
    {
        $request = new ImageGenerationRequest(
            prompt: 'A Laravel robot',
            model: 'gpt-image-1',
            size: '1024x1024',
            n: 2,
            quality: 'high',
            metadata: ['post_id' => 10],
        );

        $this->assertSame('A Laravel robot', $request->prompt);
        $this->assertSame('gpt-image-1', $request->model);
        $this->assertSame('1024x1024', $request->size);
        $this->assertSame(2, $request->n);
        $this->assertSame('high', $request->quality);
        $this->assertSame(['post_id' => 10], $request->metadata);
    }

    public function test_image_generation_response_exposes_first_image_helpers(): void
    {
        $response = new ImageGenerationResponse(
            images: [
                [
                    'url' => 'https://example.test/image.png',
                    'b64_json' => 'encoded',
                ],
            ],
            model: 'gpt-image-1',
            provider: 'openai',
        );

        $this->assertSame('https://example.test/image.png', $response->firstUrl());
        $this->assertSame('encoded', $response->firstBase64());
    }
}
