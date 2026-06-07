# MCP Architecture

Laravel Agents v0.4 adds Model Context Protocol support in two directions:

- consume tools exposed by remote MCP servers;
- expose Laravel application tools through an MCP server.

The design should keep MCP as a protocol adapter around the existing tool system. Agents should continue to reason about `Tool` definitions and `ToolBag` instances, while MCP clients and servers translate between Laravel Agents tool metadata and MCP tool messages.

## Goals

- Register remote MCP servers and make their tools available to agents.
- Run a Laravel MCP server that exposes selected package tools, application tools, and controller actions.
- Convert schemas between Laravel Agents tools, JSON Schema, and MCP tool definitions.
- Provide authentication and authorization hooks for inbound server requests and outbound client calls.
- Allow each agent to opt into specific MCP servers and tool names.
- Reuse existing Laravel controllers without forcing teams to rewrite every action as a dedicated agent tool.

## Non-Goals

- Replacing the existing `Tool` contract.
- Exposing every route or controller automatically.
- Bypassing Laravel authorization, validation, middleware, or request boundaries.
- Supporting every MCP transport in the first slice.

## Current Boundary

```txt
src/
  MCP/
    Client/
      McpClient.php
      McpServerConnection.php
      Transports/
        HttpMcpTransport.php
    Server/
      McpServer.php
      McpRequestHandler.php
      McpToolRegistry.php
      HttpMcpController.php
    Tools/
      McpRemoteTool.php
      ControllerTool.php
      RouteTool.php
    Schema/
      ToolSchemaConverter.php
      JsonSchemaNormalizer.php
    Auth/
      AuthorizesMcpRequests.php
      McpAuthenticationResult.php
    Data/
      McpServerDefinition.php
      McpToolDefinition.php
      McpToolResult.php
```

MCP code should depend on the public `Contracts\Tool` interface when it needs to execute local tools. The agent runtime should only need a way to merge local tools and remote MCP tools into the agent `ToolBag`.

## Configuration Shape

The package config includes an `mcp` section:

```php
'mcp' => [
    'enabled' => env('AGENTS_MCP_ENABLED', false),

    'server' => [
        'enabled' => env('AGENTS_MCP_SERVER_ENABLED', false),
        'route' => env('AGENTS_MCP_SERVER_ROUTE', '/agents/mcp'),
        'middleware' => ['api'],
        'auth' => null,
        'tools' => [],
        'controllers' => [],
        'routes' => [],
    ],

    'clients' => [
        'servers' => [
            'docs' => [
                'transport' => 'http',
                'url' => env('AGENTS_MCP_DOCS_URL'),
                'headers' => [
                    'Authorization' => 'Bearer '.env('AGENTS_MCP_DOCS_TOKEN'),
                ],
            ],
        ],
    ],

    'agents' => [
        // App\Agents\ResearchAgent::class => [
        //     'servers' => ['docs'],
        //     'tools' => ['docs.search', 'docs.fetch'],
        // ],
    ],
],
```

This keeps server publishing, remote server registration, and per-agent permission policy in one predictable place.

## MCP Client

The client should discover tools from configured remote MCP servers and expose each remote tool as a local `Tool` implementation.

`McpRemoteTool` should implement `Contracts\Tool`:

- `name()` returns a stable namespaced name, such as `docs.search`.
- `description()` comes from the remote server.
- `schema()` returns normalized JSON Schema for the tool input.
- `handle(array $input)` calls the remote MCP server and returns the tool result.

The first implementation should support HTTP transport. Stdio can follow once the abstraction is stable.

Expected app usage:

```php
final class ResearchAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('researcher');
        $this->model('openai/gpt-4.1-mini');
        $this->withMcpServers(['docs']);
    }
}
```

If the public API should stay even smaller, `withMcpServers()` can expand the agent tool bag during `bootAgent()` by resolving an `McpToolRegistry`.

## MCP Server

The server should expose a curated registry of Laravel tools. The registry can include:

- concrete classes implementing `Contracts\Tool`;
- `ClosureTool` instances;
- controller methods adapted through `ControllerTool`;
- named routes adapted through `RouteTool`.

The MCP server should not scan and publish every route by default. Exposed tools should be explicit, named, described, schema-backed, and authorized.

Expected registration options:

```php
'mcp' => [
    'server' => [
        'tools' => [
            App\Agents\Tools\FindCustomerTool::class,
        ],

        'controllers' => [
            'customers.find' => [
                'uses' => [App\Http\Controllers\CustomerController::class, 'show'],
                'description' => 'Find a customer by id.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],

        'routes' => [
            'orders.refund' => [
                'route' => 'orders.refund',
                'method' => 'POST',
                'description' => 'Issue a refund for an order.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['order', 'amount'],
                    'properties' => [
                        'order' => ['type' => 'integer'],
                        'amount' => ['type' => 'number', 'minimum' => 0],
                        'reason' => ['type' => 'string'],
                    ],
                ],
                'map' => [
                    'route' => ['order'],
                    'body' => ['amount', 'reason'],
                ],
            ],
        ],
    ],
],
```

## Controller And Route Reuse

Controller reuse should be supported through an adapter rather than reflection-only magic. The adapter should let existing controller actions become MCP-friendly while keeping the app in control of the tool contract.

Recommended adapter behavior:

- Resolve the controller through the Laravel container.
- Build a Laravel request from MCP input.
- Run configured middleware when the tool is route-backed.
- Reuse form request validation when a controller method declares a `FormRequest`.
- Reuse policies and gates inside the controller as usual.
- Normalize Laravel responses into MCP tool results.
- Fail with structured errors for validation, authorization, missing models, and unexpected exceptions.

Two adapters are useful:

`ControllerTool`
: Directly invokes a controller method through the container. This is best for actions that do not need route model binding or route middleware.

`RouteTool`
: Dispatches a named route internally. This is best when the existing HTTP route lifecycle matters.

Example route-backed registration:

```php
'routes' => [
    'orders.refund' => [
        'route' => 'orders.refund',
        'method' => 'POST',
        'description' => 'Issue a refund for an order.',
        'schema' => [
            'type' => 'object',
            'required' => ['order', 'amount'],
            'properties' => [
                'order' => ['type' => 'integer'],
                'amount' => ['type' => 'number', 'minimum' => 0],
                'reason' => ['type' => 'string'],
            ],
        ],
        'map' => [
            'route' => ['order'],
            'body' => ['amount', 'reason'],
        ],
    ],
],
```

The explicit `map` prevents accidental leakage of model identifiers, query parameters, headers, or request body fields.

## Schema Conversion

The package should use JSON Schema as the internal MCP-facing schema format because the current `Tool::schema()` already returns an array.

`ToolSchemaConverter` should:

- accept current Laravel Agents tool schemas;
- normalize them into MCP-compatible input schemas;
- validate that each exposed tool has an object root schema;
- preserve `description`, `enum`, `required`, scalar types, array item schemas, and object properties;
- reject unsupported schemas with a clear exception.

Controller tools should prefer explicit schemas. Later versions may infer schemas from FormRequest rules, PHP attributes, or OpenAPI metadata, but inference should be additive.

## Authentication And Authorization

There are two separate auth surfaces:

- inbound MCP server requests calling Laravel tools;
- outbound MCP client requests from agents to remote servers.

Inbound server auth should be pluggable:

```php
interface AuthorizesMcpRequests
{
    public function authorize(Request $request, McpToolDefinition $tool): McpAuthenticationResult;
}
```

The default should deny protected server execution unless the developer enables a route and middleware stack. This lets apps use Sanctum, Passport, signed URLs, internal network middleware, API tokens, or custom guards.

Outbound client auth should live in server config and support static headers first. A later hook can refresh OAuth tokens or sign requests dynamically.

## Per-Agent MCP Permissions

Each agent should explicitly list allowed MCP servers and, optionally, allowed tool names.

Example:

```php
final class SupportAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('support');
        $this->model('openai/gpt-4.1-mini');
        $this->withMcpServers(['crm']);
        $this->allowMcpTools(['crm.customers.find', 'crm.tickets.create']);
    }
}
```

Config-level policy should also be supported for teams that prefer central governance:

```php
'agents' => [
    App\Agents\SupportAgent::class => [
        'servers' => ['crm'],
        'tools' => ['crm.customers.find', 'crm.tickets.create'],
    ],
],
```

Agent-level and config-level permissions should be intersected when both are present.

## Implemented First Slice

- Config keys and service bindings for an MCP tool registry.
- Schema normalization for existing `Tool` objects.
- HTTP JSON-RPC style MCP server endpoint with lifecycle, ping, batch, notifications, tool listing, and local tool calls.
- `ControllerTool` with explicit schema and direct container invocation.
- `RouteTool` for named routes, with custom dispatcher support for tests and non-standard runtimes.
- HTTP and stdio MCP client discovery and remote tool calls.
- `withMcpServers()` and per-agent allowed tool filtering.
- Basic JSON Schema input validation before tool execution.
- Tests for registry, schema conversion, lifecycle, batch, auth rejection, validation, controller execution, route dispatch mapping, remote calls, and agent filtering.

The first slice keeps MCP as a tool adapter around the existing agent runtime instead of changing the core tool loop.

## Testing Strategy

- Unit test schema conversion with supported and rejected schema shapes.
- Unit test `McpRemoteTool` namespacing and payload normalization.
- Feature test the MCP server endpoint with fake tools.
- Feature test authentication denial and allowed execution.
- Feature test `ControllerTool` with validation and authorization failures.
- Feature test `RouteTool` against a named route with route parameters.
- Agent test proving MCP tools appear in the prompt only when allowed.

## Open Design Questions

- Should Server-Sent Events be included after the first HTTP JSON-RPC style and stdio transports?
- Should controller tool schemas support PHP attributes in v0.4, or stay config-only until the core is stable?
- Should remote MCP tool content blocks be further normalized before entering agent step history?
