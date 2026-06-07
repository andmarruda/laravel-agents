<?php

namespace Andmarruda\LaravelAgents\Tests;

use Andmarruda\LaravelAgents\MCP\Client\McpClient;
use Andmarruda\LaravelAgents\MCP\Auth\AuthorizesMcpRequests;
use Andmarruda\LaravelAgents\MCP\Auth\McpAuthenticationResult;
use Andmarruda\LaravelAgents\MCP\Data\McpToolDefinition;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use Andmarruda\LaravelAgents\MCP\Server\McpServer;
use Andmarruda\LaravelAgents\MCP\Server\McpToolRegistry;
use Andmarruda\LaravelAgents\MCP\Tools\ControllerTool;
use Andmarruda\LaravelAgents\MCP\Tools\RouteTool;
use Andmarruda\LaravelAgents\Tests\Fakes\ExampleTool;
use Andmarruda\LaravelAgents\Tests\Fakes\MCP\CustomerController;
use Andmarruda\LaravelAgents\Tests\Fakes\MCP\FakeMcpTransport;
use Andmarruda\LaravelAgents\Tests\Fakes\MCP\McpAgent;
use Andmarruda\LaravelAgents\Tools\ClosureTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class McpTest extends TestCase
{
    public function test_schema_converter_requires_object_root(): void
    {
        $converter = new ToolSchemaConverter();

        $schema = $converter->toMcpInputSchema([
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'ignored' => ['type' => 'string', 'x-extra' => true],
            ],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['id'], $schema['required']);
        $this->assertArrayNotHasKey('x-extra', $schema['properties']['ignored']);

        $this->expectException(InvalidArgumentException::class);
        $converter->toMcpInputSchema(['type' => 'string']);
    }

    public function test_mcp_server_lists_and_calls_local_tools(): void
    {
        $registry = new McpToolRegistry();
        $registry->registerLocalTool(new ExampleTool());
        $server = new McpServer($registry);

        $list = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertSame('example', $list['result']['tools'][0]['name']);
        $this->assertSame('object', $list['result']['tools'][0]['inputSchema']['type']);

        $call = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'example',
                'arguments' => ['name' => 'Ana'],
            ],
        ]);

        $this->assertSame('hello Ana', $call['result']['content'][0]['text']);
    }

    public function test_mcp_server_supports_lifecycle_notifications_and_batch_requests(): void
    {
        $registry = new McpToolRegistry();
        $registry->registerLocalTool(new ExampleTool());
        $server = new McpServer($registry);

        $initialize = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
            ],
        ]);

        $this->assertSame('2025-06-18', $initialize['result']['protocolVersion']);
        $this->assertFalse($initialize['result']['capabilities']['tools']['listChanged']);

        $notification = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertNull($notification);

        $batch = $server->handleBatch([
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'ping',
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ],
        ]);

        $this->assertCount(2, $batch);
        $this->assertSame(1, $batch[0]['id']);
        $this->assertSame(2, $batch[1]['id']);
    }

    public function test_mcp_server_reports_validation_errors_as_tool_errors(): void
    {
        $registry = new McpToolRegistry();
        $registry->registerLocalTool(new ExampleTool());
        $server = new McpServer($registry);

        $call = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'example',
                'arguments' => [],
            ],
        ]);

        $this->assertTrue($call['result']['isError']);
        $this->assertStringContainsString('Missing required field', $call['result']['content'][0]['text']);
    }

    public function test_mcp_server_returns_structured_content_for_array_results(): void
    {
        $registry = new McpToolRegistry();
        $registry->registerLocalTool(new ClosureTool(
            name: 'customers.profile',
            description: 'Customer profile.',
            schema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
            handler: fn (array $input) => [
                'id' => $input['id'],
                'name' => 'Ada Lovelace',
            ],
        ));
        $server = new McpServer($registry);

        $call = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'customers.profile',
                'arguments' => ['id' => 10],
            ],
        ]);

        $this->assertSame('Ada Lovelace', $call['result']['structuredContent']['name']);
        $this->assertStringContainsString('Ada Lovelace', $call['result']['content'][0]['text']);
    }

    public function test_controller_tool_invokes_existing_controller_action(): void
    {
        $tool = new ControllerTool(
            name: 'customers.find',
            description: 'Find a customer.',
            controller: CustomerController::class,
            method: 'show',
            schema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        );

        $this->assertSame('customers.find', $tool->name());
        $this->assertSame('Ada Lovelace', $tool->handle(['id' => 10])['name']);
    }

    public function test_route_tool_maps_input_to_dispatcher(): void
    {
        $tool = new RouteTool(
            name: 'orders.refund',
            description: 'Refund an order.',
            route: 'orders.refund',
            method: 'POST',
            schema: ['type' => 'object'],
            map: [
                'route' => ['order'],
                'body' => ['amount', 'reason'],
            ],
            dispatcher: fn (string $route, string $method, array $routeParameters, array $body) => compact(
                'route',
                'method',
                'routeParameters',
                'body',
            ),
        );

        $result = $tool->handle([
            'order' => 55,
            'amount' => 19.99,
            'reason' => 'duplicate charge',
        ]);

        $this->assertSame('orders.refund', $result['route']);
        $this->assertSame(['order' => 55], $result['routeParameters']);
        $this->assertSame(['amount' => 19.99, 'reason' => 'duplicate charge'], $result['body']);
    }

    public function test_mcp_server_can_reject_unauthorized_tool_calls(): void
    {
        $registry = new McpToolRegistry();
        $registry->registerLocalTool(new ExampleTool());
        $server = new McpServer($registry, new class implements AuthorizesMcpRequests {
            public function authorize(mixed $request, ?McpToolDefinition $tool = null): McpAuthenticationResult
            {
                return $tool?->name === 'example'
                    ? McpAuthenticationResult::deny('Denied.')
                    : McpAuthenticationResult::allow();
            }
        });

        $call = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'example',
                'arguments' => ['name' => 'Ana'],
            ],
        ]);

        $this->assertSame('Denied.', $call['error']['message']);
    }

    public function test_remote_mcp_tools_are_namespaced_and_callable(): void
    {
        $transport = new FakeMcpTransport([
            'tools/list' => [
                'tools' => [[
                    'name' => 'customers.find',
                    'description' => 'Find a customer.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                ]],
            ],
            'tools/call' => [
                'content' => [[
                    'type' => 'text',
                    'text' => '{"id":10,"name":"Ada Lovelace"}',
                ]],
            ],
        ]);

        $registry = new McpToolRegistry();
        $tools = $registry->toolsFromRemoteClient('crm', new McpClient($transport));

        $this->assertArrayHasKey('crm.customers.find', $tools);
        $this->assertSame('crm.customers.find', $tools['crm.customers.find']->name());
        $this->assertSame([[
            'type' => 'text',
            'text' => '{"id":10,"name":"Ada Lovelace"}',
        ]], $tools['crm.customers.find']->handle(['id' => 10]));
    }

    public function test_agent_loads_allowed_mcp_tools_from_registry(): void
    {
        $transport = new FakeMcpTransport([
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'customers.find',
                        'description' => 'Find a customer.',
                        'inputSchema' => ['type' => 'object'],
                    ],
                    [
                        'name' => 'customers.delete',
                        'description' => 'Delete a customer.',
                        'inputSchema' => ['type' => 'object'],
                    ],
                ],
            ],
        ]);

        $registry = new class(new McpClient($transport)) extends McpToolRegistry {
            public function __construct(protected McpClient $client)
            {
                parent::__construct();
            }

            public function remoteToolsForServer(string $serverName): array
            {
                return $this->toolsFromRemoteClient($serverName, $this->client);
            }
        };

        $agent = (new McpAgent())->setMcpToolRegistry($registry);
        $agent->bootAgent();

        $this->assertArrayHasKey('crm.customers.find', $agent->tools()->all());
        $this->assertArrayNotHasKey('crm.customers.delete', $agent->tools()->all());
    }
}
