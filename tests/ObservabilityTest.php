<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\Observability\Exporters\NullTraceExporter;
use Andmarruda\LaravelAgents\Observability\Exporters\OpenTelemetryTraceExporter;
use Andmarruda\LaravelAgents\Observability\Stores\InMemoryTraceStore;
use Andmarruda\LaravelAgents\Observability\Support\CostCalculator;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelPort;
use Andmarruda\LaravelAgents\Tests\Fakes\FakeModelRouter;
use Andmarruda\LaravelAgents\Tests\Fakes\ResearchAgent;
use Andmarruda\LaravelAgents\Tests\Fakes\ToolCallingAgent;
use Andmarruda\LaravelAgents\Workflows\Workflow;
use PHPUnit\Framework\TestCase;

class ObservabilityTest extends TestCase
{
    public function test_agent_run_records_model_usage_cost_and_trace_metadata(): void
    {
        $store = new InMemoryTraceStore();
        $traces = new TraceManager(
            $store,
            new NullTraceExporter(),
            new CostCalculator([
                'fake/fake-model' => ['input_per_1k' => 0.10, 'output_per_1k' => 0.20],
            ]),
        );
        $model = new FakeModelPort(['Observed answer']);
        $agent = (new ResearchAgent())
            ->setTraceManager($traces)
            ->setModelRouter(new FakeModelRouter([
                'worker-model' => $model,
                'default' => $model,
            ]));

        $response = $agent->generate('Trace this.');
        $trace = $store->get($response->meta['trace_id']);

        $this->assertNotNull($trace);
        $this->assertSame('agent.run', $trace->name);
        $this->assertSame('ok', $trace->status->value);
        $this->assertCount(1, $trace->spans);
        $this->assertSame('model.generate', $trace->spans[0]->name);
        $this->assertSame('fake', $trace->spans[0]->attributes['model.provider']);
        $this->assertSame(1, $trace->spans[0]->attributes['model.usage']['total_tokens']);
        $this->assertSame(0.0, $trace->spans[0]->attributes['model.cost']['amount']);
    }

    public function test_tool_calls_are_recorded_as_spans(): void
    {
        $store = new InMemoryTraceStore();
        $traces = new TraceManager($store, new NullTraceExporter(), new CostCalculator());
        $model = new FakeModelPort([
            '{"action":"tool","tool":"example","input":{"name":"Ana"}}',
            'Done',
        ]);
        $agent = (new ToolCallingAgent())
            ->setTraceManager($traces)
            ->setModelRouter(new FakeModelRouter([
                'tool-model' => $model,
                'default' => $model,
            ]));

        $response = $agent->generate('Use tool.');
        $trace = $store->get($response->meta['trace_id']);
        $spanNames = array_map(fn ($span) => $span->name, $trace->spans);

        $this->assertSame(['model.generate', 'tool.call', 'model.generate'], $spanNames);
        $this->assertSame('example', $trace->spans[1]->attributes['tool.name']);
        $this->assertSame('hello Ana', $trace->spans[1]->attributes['tool.result']);
    }

    public function test_workflow_run_records_node_spans(): void
    {
        $store = new InMemoryTraceStore();
        $traces = new TraceManager($store, new NullTraceExporter(), new CostCalculator());

        $response = Workflow::make('observed-workflow')
            ->setTraceManager($traces)
            ->then('double', fn (int $value): int => $value * 2)
            ->run(5);

        $trace = $store->get($response->meta['trace_id']);

        $this->assertSame(10, $response->data);
        $this->assertSame('workflow.run', $trace->name);
        $this->assertSame('workflow.step', $trace->spans[0]->name);
        $this->assertSame('double', $trace->spans[0]->attributes['workflow.node']);
    }

    public function test_open_telemetry_exporter_builds_span_payload(): void
    {
        $store = new InMemoryTraceStore();
        $payloads = [];
        $exporter = new OpenTelemetryTraceExporter(function (array $payload) use (&$payloads): void {
            $payloads[] = $payload;
        });
        $traces = new TraceManager($store, $exporter, new CostCalculator());
        $trace = $traces->startTrace('manual');
        $span = $traces->startSpan('unit', 'internal', ['key' => 'value']);

        $traces->finishSpan($span);
        $traces->finishTrace($trace);

        $this->assertSame('unit', $payloads[0]['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name']);
        $this->assertSame('internal', $payloads[0]['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['kind']);
    }

    public function test_trace_attributes_redact_configured_sensitive_keys(): void
    {
        $store = new InMemoryTraceStore();
        $traces = new TraceManager(
            $store,
            new NullTraceExporter(),
            new CostCalculator(),
            enabled: true,
            redactKeys: ['token'],
        );
        $trace = $traces->startTrace('secure', ['nested' => ['token' => 'secret', 'safe' => 'value']]);
        $traces->finishTrace($trace);

        $stored = $store->get($trace->id);
        $this->assertSame('[REDACTED]', $stored->attributes['nested']['token']);
        $this->assertSame('value', $stored->attributes['nested']['safe']);
    }
}
