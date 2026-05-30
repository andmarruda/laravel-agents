<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelPort;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelRouter;
use Andmarruda\LaravelAgents\Tests\Fakes\ManagerAgent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SupervisorAgentTest extends TestCase
{
    public function test_supervisor_delegates_to_worker_and_returns_final_answer(): void
    {
        $supervisorModel = new FakeModelPort([
            '{"action":"delegate","agent":"researcher","task":"Research the launch plan"}',
            '{"action":"final","answer":"Final launch plan"}',
        ]);
        $workerModel = new FakeModelPort(['Research notes']);
        $agent = (new ManagerAgent())
            ->setModelRouter(new FakeModelRouter([
                'supervisor-model' => $supervisorModel,
                'worker-model' => $workerModel,
                'default' => $supervisorModel,
            ]));

        $response = $agent->generate('Create a launch plan.', [
            'tenant_id' => 10,
        ]);

        $this->assertSame('Final launch plan', $response->content);
        $this->assertSame('manager', $response->meta['agent']);
        $this->assertSame(1, $response->meta['steps']);
        $this->assertCount(1, $response->steps);
        $this->assertSame('researcher', $response->steps[0]['agent']);
        $this->assertSame('Research the launch plan', $response->steps[0]['task']);
        $this->assertSame('Research notes', $response->steps[0]['result']);

        $this->assertCount(2, $supervisorModel->messages);
        $this->assertStringContainsString('strict JSON only', $supervisorModel->messages[0][0]['content']);
        $this->assertStringContainsString('researcher', $supervisorModel->messages[0][1]['content']);
        $this->assertStringContainsString('"tenant_id":10', $supervisorModel->messages[0][1]['content']);
        $this->assertStringContainsString('previous_steps', $workerModel->messages[0][0]['content']);
    }

    public function test_supervisor_runs_fallback_finalization_when_max_steps_is_reached(): void
    {
        $supervisorModel = new FakeModelPort([
            '{"action":"delegate","agent":"researcher","task":"Step one"}',
            '{"action":"delegate","agent":"writer","task":"Step two"}',
            '{"action":"delegate","agent":"researcher","task":"Step three"}',
            'Fallback final answer',
        ]);
        $workerModel = new FakeModelPort([
            'Research notes',
            'Written draft',
            'More research',
        ]);
        $agent = (new ManagerAgent())
            ->setModelRouter(new FakeModelRouter([
                'supervisor-model' => $supervisorModel,
                'worker-model' => $workerModel,
                'default' => $supervisorModel,
            ]));

        $response = $agent->generate('Create a launch plan.');

        $this->assertSame('Fallback final answer', $response->content);
        $this->assertTrue($response->meta['max_steps_reached']);
        $this->assertCount(3, $response->steps);
        $this->assertStringContainsString('Create the best final answer', $supervisorModel->messages[3][1]['content']);
    }

    public function test_supervisor_rejects_invalid_json_decisions(): void
    {
        $supervisorModel = new FakeModelPort(['not-json']);
        $agent = (new ManagerAgent())
            ->setModelRouter(new FakeModelRouter([
                'supervisor-model' => $supervisorModel,
                'worker-model' => new FakeModelPort(),
                'default' => $supervisorModel,
            ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supervisor returned invalid JSON decision');

        $agent->generate('Create a launch plan.');
    }

    public function test_supervisor_rejects_unknown_agents(): void
    {
        $supervisorModel = new FakeModelPort([
            '{"action":"delegate","agent":"unknown","task":"Do work"}',
        ]);
        $agent = (new ManagerAgent())
            ->setModelRouter(new FakeModelRouter([
                'supervisor-model' => $supervisorModel,
                'worker-model' => new FakeModelPort(),
                'default' => $supervisorModel,
            ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supervisor selected unknown agent [unknown].');

        $agent->generate('Create a launch plan.');
    }
}
