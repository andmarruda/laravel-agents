<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Data\AgentResponse;
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
}
