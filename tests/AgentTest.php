<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelPort;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelRouter;
use Andmarruda\LaravelAgents\Tests\Fakes\ResearchAgent;
use Andmarruda\LaravelAgents\Tests\Fakes\ToolCallingAgent;
use PHPUnit\Framework\TestCase;

class AgentTest extends TestCase
{
    public function test_worker_agent_generates_response_and_metadata(): void
    {
        $model = new FakeModelPort(['Research result']);
        $agent = (new ResearchAgent())
            ->setModelRouter(new FakeModelRouter([
                'worker-model' => $model,
                'default' => $model,
            ]));

        $response = $agent->generate('Find useful context.', [
            'project_id' => 123,
        ]);

        $this->assertSame('Research result', $response->content);
        $this->assertSame('researcher', $response->meta['agent']);
        $this->assertSame('fake-model', $response->meta['model']);
        $this->assertSame('fake', $response->meta['provider']);
        $this->assertSame(['total_tokens' => 1], $response->meta['usage']);

        $this->assertSame('system', $model->messages[0][0]['role']);
        $this->assertStringContainsString('Research carefully.', $model->messages[0][0]['content']);
        $this->assertStringContainsString('"project_id":123', $model->messages[0][0]['content']);
        $this->assertSame(['role' => 'user', 'content' => 'Find useful context.'], $model->messages[0][1]);
    }

    public function test_agent_configures_only_once(): void
    {
        $agent = new class extends ResearchAgent {
            public int $configuredTimes = 0;

            public function configure(): void
            {
                $this->configuredTimes++;

                parent::configure();
            }
        };

        $agent->bootAgent();
        $agent->bootAgent();

        $this->assertSame(1, $agent->configuredTimes);
    }

    public function test_agent_executes_tool_calls_and_continues_with_result(): void
    {
        $model = new FakeModelPort([
            '{"action":"tool","tool":"example","input":{"name":"Ana"}}',
            'Final answer with tool result',
        ]);
        $agent = (new ToolCallingAgent())
            ->setModelRouter(new FakeModelRouter([
                'tool-model' => $model,
                'default' => $model,
            ]));

        $response = $agent->generate('Use a tool.');

        $this->assertSame('Final answer with tool result', $response->content);
        $this->assertSame(1, $response->meta['tool_steps']);
        $this->assertSame('example', $response->steps[0]['tool']);
        $this->assertSame(['name' => 'Ana'], $response->steps[0]['input']);
        $this->assertSame('hello Ana', $response->steps[0]['result']);
        $this->assertStringContainsString('Available tools', $model->messages[0][0]['content']);
        $this->assertStringContainsString('Tool result for example', $model->messages[1][3]['content']);
    }
}
