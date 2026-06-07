# MCP Usage Guide

Laravel Agents can expose selected Laravel behavior as MCP tools and consume tools from remote MCP servers.

The first implementation focuses on:

- local MCP tool registry;
- JSON-RPC style `initialize`, `notifications/initialized`, `ping`, `tools/list`, and `tools/call`;
- exposing existing `Tool` classes;
- exposing controller actions with `ControllerTool`;
- exposing named routes with `RouteTool`;
- consuming remote HTTP and stdio MCP tools as normal agent tools;
- per-agent remote MCP server and tool allow-lists.

## 1. Enable The MCP Server

Publish the config and enable the server:

```env
AGENTS_MCP_ENABLED=true
AGENTS_MCP_SERVER_ENABLED=true
AGENTS_MCP_SERVER_ROUTE=/agents/mcp
```

Example config:

```php
'mcp' => [
    'enabled' => env('AGENTS_MCP_ENABLED', false),

    'server' => [
        'enabled' => env('AGENTS_MCP_SERVER_ENABLED', false),
        'route' => env('AGENTS_MCP_SERVER_ROUTE', '/agents/mcp'),
        'middleware' => ['api', 'auth:sanctum'],
        'auth' => null,
        'tools' => [],
        'controllers' => [],
        'routes' => [],
    ],

    'clients' => [
        'servers' => [],
    ],

    'agents' => [],
],
```

When enabled in a Laravel app, the package registers a POST route for the configured MCP endpoint.

## 2. Expose A Normal Laravel Agents Tool

If you already have a tool class, expose it directly:

```php
use Andmarruda\LaravelAgents\Contracts\Tool;

final class FindCustomerTool implements Tool
{
    public function name(): string
    {
        return 'customers.find';
    }

    public function description(): string
    {
        return 'Find a customer by id and return CRM profile details.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
        ];
    }

    public function handle(array $input): mixed
    {
        return Customer::query()->findOrFail($input['id'])->toArray();
    }
}
```

Register it:

```php
'mcp' => [
    'server' => [
        'tools' => [
            App\Agents\Tools\FindCustomerTool::class,
        ],
    ],
],
```

Real-world use: let an internal support assistant fetch customer context from your Laravel app without exposing a broad API surface.

## 3. Reuse An Existing Controller Action

If the behavior already lives in a controller, register it as a controller-backed MCP tool:

```php
'mcp' => [
    'server' => [
        'controllers' => [
            'customers.profile' => [
                'uses' => [App\Http\Controllers\Api\CustomerController::class, 'show'],
                'description' => 'Return customer profile, plan, and billing status.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ],
],
```

`ControllerTool` resolves the controller through the container when Laravel is available. It can pass input by parameter name, pass the whole input array to an `array` parameter, or create an `Illuminate\Http\Request` when the action expects a request.

Good fit:

```php
public function show(int $id): array
{
    return CustomerResource::make(Customer::findOrFail($id))->resolve();
}
```

Also works:

```php
public function search(Request $request): array
{
    return Customer::query()
        ->where('email', $request->string('email'))
        ->limit(5)
        ->get()
        ->toArray();
}
```

Real-world use: your admin dashboard already has a stable API controller for customer lookup, and you want the MCP server to reuse it instead of duplicating the query in a new tool.

## 4. Reuse A Named Route

Use `RouteTool` when route model binding, route middleware, policies, or form requests are important:

```php
'mcp' => [
    'server' => [
        'routes' => [
            'orders.refund' => [
                'route' => 'orders.refund',
                'method' => 'POST',
                'description' => 'Issue a partial refund for an order.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['order', 'amount', 'reason'],
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

Real-world use: a finance operations agent can request refunds through the same route your admin panel uses, keeping middleware, model binding, validation, and audit logging in one path.

## 5. Call The MCP Server

The server accepts JSON-RPC style requests.

List tools:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list"
}
```

Call a tool:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "customers.profile",
    "arguments": {
      "id": 123
    }
  }
}
```

Tool results are returned as MCP content blocks:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"id\":123,\"name\":\"Ada Lovelace\"}"
      }
    ]
  }
}
```

Array/object results also include `structuredContent`, so clients can consume typed data without reparsing the text block.

## 6. Consume A Remote MCP Server

Register a remote HTTP server:

```php
'mcp' => [
    'clients' => [
        'servers' => [
            'inventory' => [
                'transport' => 'http',
                'url' => env('AGENTS_MCP_INVENTORY_URL'),
                'headers' => [
                    'Authorization' => 'Bearer '.env('AGENTS_MCP_INVENTORY_TOKEN'),
                ],
            ],
        ],
    ],
],
```

You can also register a local stdio server:

```php
'mcp' => [
    'clients' => [
        'servers' => [
            'filesystem' => [
                'transport' => 'stdio',
                'command' => ['node', base_path('mcp/filesystem-server.js')],
                'cwd' => base_path(),
                'env' => [
                    'APP_ENV' => env('APP_ENV'),
                ],
            ],
        ],
    ],
],
```

Allow an agent to use it:

```php
final class MerchandisingAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('merchandising');
        $this->description('Answers catalog and stock questions.');
        $this->instructions('Use inventory tools before answering availability questions.');
        $this->model('openai/gpt-4.1-mini');
        $this->withMcpServers(['inventory']);
        $this->allowMcpTools([
            'inventory.products.search',
            'inventory.stock.lookup',
        ]);
    }
}
```

Remote tool names are namespaced by server. A remote `stock.lookup` tool from the `inventory` server becomes `inventory.stock.lookup`.

Real-world use: your Laravel app can answer “Can we ship 40 units of SKU ABC to Miami this week?” by calling a separate warehouse MCP server.

## 7. Centralize Agent Permissions

You can restrict MCP tools from config too:

```php
'mcp' => [
    'agents' => [
        App\Agents\SupportAgent::class => [
            'servers' => ['crm', 'billing'],
            'tools' => [
                'crm.customers.find',
                'crm.tickets.create',
                'billing.invoices.latest',
            ],
        ],
    ],
],
```

If an agent defines `withMcpServers()` or `allowMcpTools()` and config also defines permissions, Laravel Agents uses the intersection.

Real-world use: a support agent can read customer and invoice data, while a finance agent can call refund tools, and neither agent sees tools outside its job.

## 8. Add Custom Authorization

For normal apps, middleware is often enough:

```php
'middleware' => ['api', 'auth:sanctum'],
```

For tool-aware authorization, implement:

```php
use Andmarruda\LaravelAgents\MCP\Auth\AuthorizesMcpRequests;
use Andmarruda\LaravelAgents\MCP\Auth\McpAuthenticationResult;
use Andmarruda\LaravelAgents\MCP\Data\McpToolDefinition;

final class AuthorizeMcpRequest implements AuthorizesMcpRequests
{
    public function authorize(mixed $request, ?McpToolDefinition $tool = null): McpAuthenticationResult
    {
        if ($tool?->name === 'orders.refund' && ! $request->user()?->can('refund orders')) {
            return McpAuthenticationResult::deny('You cannot refund orders.');
        }

        return McpAuthenticationResult::allow();
    }
}
```

Register it:

```php
'auth' => App\Mcp\AuthorizeMcpRequest::class,
```

## 9. Choosing Tool, Controller, Or Route

Use a dedicated `Tool` class when the action is agent-first or service-first.

Use `ControllerTool` when an existing controller method is already a clean application boundary and does not rely heavily on route middleware.

Use `RouteTool` when the route lifecycle is part of the behavior: model binding, middleware, policies, form requests, throttling, or audit logging.

## 10. Production Notes

The MCP server validates JSON-RPC 2.0 request shape, rejects `null` request IDs, ignores notifications without returning a response, supports batch requests, and reports tool execution or input validation failures as MCP tool results with `isError: true`.

The current server capability is tools-only. Resources, prompts, sampling, and elicitation are intentionally outside this package slice until the agent runtime has first-class use cases for them.
