<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Agents\Agent;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\ApprovalRequiredException;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\GuardrailDeniedException;
use Andmarruda\LaravelAgents\Guardrails\GuardrailPipeline;
use Andmarruda\LaravelAgents\Guardrails\Policies\AllowedTools;
use Andmarruda\LaravelAgents\Guardrails\Policies\JsonSchema;
use Andmarruda\LaravelAgents\Guardrails\Policies\MaxInputLength;
use Andmarruda\LaravelAgents\Guardrails\Policies\RedactSensitiveData;
use Andmarruda\LaravelAgents\Guardrails\Policies\RequireApproval;
use Andmarruda\LaravelAgents\Guardrails\Policies\ValidateToolArguments;
use Andmarruda\LaravelAgents\Guardrails\Stores\InMemoryApprovalStore;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelPort;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelRouter;
use Andmarruda\LaravelAgents\Tests\Fakes\ToolCallingAgent;
use Andmarruda\LaravelAgents\Workflows\InMemoryWorkflowStore;
use Andmarruda\LaravelAgents\Tests\Fakes\ApprovalWorkflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use DateTimeImmutable;

class GuardrailTest extends TestCase
{
    public function test_pipeline_modifies_and_denies_content(): void
    {
        $pipeline = new GuardrailPipeline();
        $result = $pipeline->run(
            'Contact ana@example.com',
            new GuardrailContext('model.input'),
            [new RedactSensitiveData()],
        );

        $this->assertSame('Contact [REDACTED:EMAIL]', $result->value);

        $this->expectException(GuardrailDeniedException::class);
        $pipeline->run('too long', new GuardrailContext('model.input'), [new MaxInputLength(3)]);
    }

    public function test_tool_permissions_and_argument_schema_are_enforced(): void
    {
        $pipeline = new GuardrailPipeline([new ValidateToolArguments()]);
        $context = new GuardrailContext('tool', [
            'tool_schema' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ], tool: 'delete_customer');

        $this->expectException(GuardrailDeniedException::class);
        $pipeline->run(['id' => 'wrong'], $context, [new AllowedTools(['delete_customer'])]);
    }

    public function test_approval_is_single_use_and_rejects_mutation(): void
    {
        $store = new InMemoryApprovalStore();
        $pipeline = new GuardrailPipeline(approvals: $store);
        $context = new GuardrailContext('tool', tool: 'delete_customer');

        try {
            $pipeline->run(['id' => 10], $context, [new RequireApproval(['delete_customer'])]);
            $this->fail('Approval exception was not thrown.');
        } catch (ApprovalRequiredException $exception) {
            $store->approve($exception->approval->id);
            $pipeline->run(
                ['id' => 10],
                $context->with('approval_id', $exception->approval->id),
                [new RequireApproval(['delete_customer'])],
            );

            $this->expectException(RuntimeException::class);
            $store->consume($exception->approval->id, ['id' => 10]);
        }
    }

    public function test_approval_expires_and_rejects_payload_mutation(): void
    {
        $store = new InMemoryApprovalStore();
        $expired = \Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest::create(
            'delete',
            ['id' => 1],
            new DateTimeImmutable('-1 second'),
        );
        $store->put($expired);

        $this->assertSame('expired', $store->get($expired->id)->status->value);

        $approval = \Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest::create('delete', ['id' => 1]);
        $store->put($approval);
        $store->approve($approval->id);

        $this->expectException(RuntimeException::class);
        $store->consume($approval->id, ['id' => 2]);
    }

    public function test_agent_retries_invalid_structured_output_and_records_attempts(): void
    {
        $model = new FakeModelPort(['not-json', '{"answer":"ok"}']);
        $agent = new class extends Agent {
            public function configure(): void
            {
                $this->model('guarded');
                $this->guardrails([
                    new JsonSchema([
                        'type' => 'object',
                        'required' => ['answer'],
                        'properties' => ['answer' => ['type' => 'string']],
                    ], decodeJson: true),
                ]);
                $this->corrections(2);
            }
        };
        $agent->setModelRouter(new FakeModelRouter(['guarded' => $model, 'default' => $model]));

        $response = $agent->generate('Answer.');

        $this->assertSame('{"answer":"ok"}', $response->content);
        $this->assertSame(1, $response->meta['correction_attempts']);
        $this->assertStringContainsString('Validation errors', $model->messages[1][2]['content']);
    }

    public function test_agent_applies_tool_approval_and_schema_validation(): void
    {
        $model = new FakeModelPort(['{"action":"tool","tool":"example","input":{}}']);
        $agent = (new ToolCallingAgent())
            ->setModelRouter(new FakeModelRouter(['tool-model' => $model, 'default' => $model]))
            ->setGuardrailPipeline(new GuardrailPipeline([new ValidateToolArguments()]));

        $this->expectException(GuardrailDeniedException::class);
        $agent->generate('Use it.');
    }

    public function test_per_run_guardrails_are_cleared_after_the_run(): void
    {
        $model = new FakeModelPort(['ok']);
        $agent = (new class extends Agent {
            public function configure(): void
            {
                $this->model('guarded');
            }
        })->setModelRouter(new FakeModelRouter(['guarded' => $model, 'default' => $model]));

        try {
            $agent->withRunGuardrails([new MaxInputLength(2)])->generate('long');
            $this->fail('Run policy did not deny the input.');
        } catch (GuardrailDeniedException) {
        }

        $this->assertSame('ok', $agent->generate('long')->content);
    }

    public function test_workflow_approval_store_prevents_replay(): void
    {
        $approvals = new InMemoryApprovalStore();
        $snapshots = new InMemoryWorkflowStore();
        $workflow = (new ApprovalWorkflow())->setApprovalStore($approvals);
        $suspended = $workflow->run(['id' => 1], store: $snapshots);
        $approvalId = $suspended->snapshot->approval['approval_id'];
        $approvals->approve($approvalId);

        $response = (new ApprovalWorkflow())
            ->setApprovalStore($approvals)
            ->resumeWithApproval($suspended->snapshot, $approvalId, $snapshots);

        $this->assertSame('completed', $response->status);
        $this->expectException(RuntimeException::class);
        $workflow->resumeWithApproval($suspended->snapshot, $approvalId, $snapshots);
    }
}
