<?php

namespace Andmarruda\LaravelAgents\MCP\Server;

use Andmarruda\LaravelAgents\MCP\Auth\AuthorizesMcpRequests;
use Andmarruda\LaravelAgents\MCP\Data\McpToolDefinition;
use Andmarruda\LaravelAgents\MCP\Data\McpToolResult;
use Andmarruda\LaravelAgents\MCP\Schema\JsonSchemaValidator;
use Andmarruda\LaravelAgents\MCP\Schema\ToolSchemaConverter;
use Throwable;

class McpServer
{
    protected const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        protected McpToolRegistry $registry,
        protected ?AuthorizesMcpRequests $authorizer = null,
        protected ?ToolSchemaConverter $schemaConverter = null,
        protected ?JsonSchemaValidator $schemaValidator = null,
        protected int $toolsPageSize = 100,
    ) {
        $this->schemaConverter ??= new ToolSchemaConverter();
        $this->schemaValidator ??= new JsonSchemaValidator();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function handle(array $payload, mixed $request = null): ?array
    {
        $id = $payload['id'] ?? null;

        try {
            $this->assertJsonRpcRequest($payload);

            $isNotification = ! array_key_exists('id', $payload);
            $method = (string) $payload['method'];
            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'notifications/initialized' => [],
                'ping' => [],
                'tools/list' => $this->listTools($params, $request),
                'tools/call' => $this->callTool($params, $request),
                default => throw new \InvalidArgumentException("Unsupported MCP method [{$method}]."),
            };

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            if (! array_key_exists('id', $payload)) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => $this->errorCodeFor($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array<string, mixed>>
     */
    public function handleBatch(array $payloads, mixed $request = null): array
    {
        $responses = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                $responses[] = $this->error(null, -32600, 'Invalid JSON-RPC request.');
                continue;
            }

            $response = $this->handle($payload, $request);

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function initialize(array $params = []): array
    {
        return [
            'protocolVersion' => (string) ($params['protocolVersion'] ?? self::PROTOCOL_VERSION),
            'serverInfo' => [
                'name' => 'laravel-agents',
                'version' => '0.4',
            ],
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{tools: array<int, array<string, mixed>>}
     */
    protected function listTools(array $params = [], mixed $request = null): array
    {
        $this->authorize($request, null);

        $tools = [];

        foreach ($this->registry->localTools() as $tool) {
            $definition = new McpToolDefinition(
                name: $tool->name(),
                description: $tool->description(),
                schema: $this->schemaConverter->toMcpInputSchema($tool->schema()),
            );

            $tools[] = $definition->toMcpArray();
        }

        $cursor = isset($params['cursor']) ? max(0, (int) $params['cursor']) : 0;
        $page = array_slice($tools, $cursor, $this->toolsPageSize);
        $nextCursor = $cursor + count($page) < count($tools) ? (string) ($cursor + count($page)) : null;
        $result = ['tools' => $page];

        if ($nextCursor !== null) {
            $result['nextCursor'] = $nextCursor;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function callTool(array $params, mixed $request = null): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tool = $this->registry->getLocalTool($name);

        $definition = new McpToolDefinition(
            name: $tool->name(),
            description: $tool->description(),
            schema: $this->schemaConverter->toMcpInputSchema($tool->schema()),
        );

        $this->authorize($request, $definition);

        try {
            $this->schemaValidator->validate($definition->schema, $arguments);

            return (new McpToolResult($tool->handle($arguments)))->toMcpArray();
        } catch (Throwable $exception) {
            return (new McpToolResult([
                'type' => 'tool_execution_error',
                'message' => $exception->getMessage(),
            ], isError: true))->toMcpArray();
        }
    }

    protected function authorize(mixed $request, ?McpToolDefinition $tool): void
    {
        if ($this->authorizer === null) {
            return;
        }

        $result = $this->authorizer->authorize($request, $tool);

        if (! $result->allowed) {
            throw new \RuntimeException($result->message ?? 'MCP request was not authorized.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function assertJsonRpcRequest(array $payload): void
    {
        if (($payload['jsonrpc'] ?? null) !== '2.0') {
            throw new \InvalidArgumentException('Invalid JSON-RPC version.');
        }

        if (! is_string($payload['method'] ?? null)) {
            throw new \InvalidArgumentException('JSON-RPC method is required.');
        }

        if (array_key_exists('id', $payload) && $payload['id'] === null) {
            throw new \InvalidArgumentException('JSON-RPC request id must not be null.');
        }

        if (array_key_exists('id', $payload) && ! is_string($payload['id']) && ! is_int($payload['id'])) {
            throw new \InvalidArgumentException('JSON-RPC request id must be a string or integer.');
        }
    }

    protected function errorCodeFor(Throwable $exception): int
    {
        return $exception instanceof \InvalidArgumentException ? -32600 : -32000;
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
