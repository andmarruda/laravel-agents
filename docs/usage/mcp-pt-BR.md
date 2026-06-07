# Guia de Uso: MCP

O Laravel Agents consegue expor partes selecionadas da sua aplicação Laravel como ferramentas MCP e consumir ferramentas de servidores MCP remotos.

A primeira implementação cobre:

- registry local de ferramentas MCP;
- métodos no estilo JSON-RPC: `initialize`, `notifications/initialized`, `ping`, `tools/list` e `tools/call`;
- exposição de classes que implementam `Tool`;
- exposição de actions de controllers com `ControllerTool`;
- exposição de rotas nomeadas com `RouteTool`;
- consumo de ferramentas MCP remotas via HTTP e stdio como ferramentas normais do agente;
- allow-list de servidores e ferramentas MCP por agente.

## 1. Habilitar O Servidor MCP

Publique a config e habilite o servidor:

```env
AGENTS_MCP_ENABLED=true
AGENTS_MCP_SERVER_ENABLED=true
AGENTS_MCP_SERVER_ROUTE=/agents/mcp
```

Exemplo de config:

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

Quando habilitado em uma aplicação Laravel, o pacote registra uma rota POST para o endpoint MCP configurado.

## 2. Expor Uma Tool Do Laravel Agents

Se você já tem uma classe de ferramenta, exponha diretamente:

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
        return 'Busca um cliente pelo id e retorna dados do CRM.';
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

Registre na config:

```php
'mcp' => [
    'server' => [
        'tools' => [
            App\Agents\Tools\FindCustomerTool::class,
        ],
    ],
],
```

Uso real: permitir que um assistente interno de suporte busque contexto de clientes sem abrir uma API ampla demais.

## 3. Reusar Uma Action De Controller

Se o comportamento já existe em um controller, registre como ferramenta MCP:

```php
'mcp' => [
    'server' => [
        'controllers' => [
            'customers.profile' => [
                'uses' => [App\Http\Controllers\Api\CustomerController::class, 'show'],
                'description' => 'Retorna perfil, plano e status financeiro do cliente.',
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

O `ControllerTool` resolve o controller pelo container quando o Laravel está disponível. Ele pode passar valores pelo nome dos parâmetros, passar todo o input para um parâmetro `array`, ou criar um `Illuminate\Http\Request` quando a action espera um request.

Bom encaixe:

```php
public function show(int $id): array
{
    return CustomerResource::make(Customer::findOrFail($id))->resolve();
}
```

Também funciona:

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

Uso real: seu painel admin já tem um controller de API estável para consulta de clientes, e o servidor MCP reaproveita esse caminho em vez de duplicar a query em uma nova tool.

## 4. Reusar Uma Rota Nomeada

Use `RouteTool` quando route model binding, middleware, policies ou form requests forem importantes:

```php
'mcp' => [
    'server' => [
        'routes' => [
            'orders.refund' => [
                'route' => 'orders.refund',
                'method' => 'POST',
                'description' => 'Emite um reembolso parcial para um pedido.',
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

Uso real: um agente financeiro pode solicitar reembolsos pela mesma rota usada no painel administrativo, mantendo middleware, binding, validação e auditoria no mesmo caminho.

## 5. Chamar O Servidor MCP

O servidor aceita requisições no estilo JSON-RPC.

Listar ferramentas:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list"
}
```

Chamar uma ferramenta:

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

Resultados voltam como blocos de conteúdo MCP:

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

Resultados em array/objeto também incluem `structuredContent`, então clientes conseguem consumir dados tipados sem reprocessar o bloco de texto.

## 6. Consumir Um Servidor MCP Remoto

Registre um servidor HTTP remoto:

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

Você também pode registrar um servidor local via stdio:

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

Permita que um agente use esse servidor:

```php
final class MerchandisingAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('merchandising');
        $this->description('Responde perguntas de catalogo e estoque.');
        $this->instructions('Use ferramentas de inventario antes de responder disponibilidade.');
        $this->model('openai/gpt-4.1-mini');
        $this->withMcpServers(['inventory']);
        $this->allowMcpTools([
            'inventory.products.search',
            'inventory.stock.lookup',
        ]);
    }
}
```

Ferramentas remotas recebem namespace pelo nome do servidor. A ferramenta remota `stock.lookup` do servidor `inventory` vira `inventory.stock.lookup`.

Uso real: sua aplicação Laravel responde “Conseguimos enviar 40 unidades do SKU ABC para Miami esta semana?” chamando um servidor MCP separado do armazém.

## 7. Centralizar Permissões Por Agente

Você também pode restringir ferramentas MCP pela config:

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

Se o agente define `withMcpServers()` ou `allowMcpTools()` e a config também define permissões, o Laravel Agents usa a interseção.

Uso real: um agente de suporte pode ler dados de cliente e faturas, enquanto um agente financeiro pode chamar ferramentas de reembolso, e nenhum deles enxerga ferramentas fora da própria função.

## 8. Adicionar Autorização Customizada

Para apps comuns, middleware costuma ser suficiente:

```php
'middleware' => ['api', 'auth:sanctum'],
```

Para autorização por ferramenta, implemente:

```php
use Andmarruda\LaravelAgents\MCP\Auth\AuthorizesMcpRequests;
use Andmarruda\LaravelAgents\MCP\Auth\McpAuthenticationResult;
use Andmarruda\LaravelAgents\MCP\Data\McpToolDefinition;

final class AuthorizeMcpRequest implements AuthorizesMcpRequests
{
    public function authorize(mixed $request, ?McpToolDefinition $tool = null): McpAuthenticationResult
    {
        if ($tool?->name === 'orders.refund' && ! $request->user()?->can('refund orders')) {
            return McpAuthenticationResult::deny('Voce nao pode reembolsar pedidos.');
        }

        return McpAuthenticationResult::allow();
    }
}
```

Registre:

```php
'auth' => App\Mcp\AuthorizeMcpRequest::class,
```

## 9. Escolher Tool, Controller Ou Route

Use uma classe `Tool` dedicada quando a ação nasce para agentes ou para um service da aplicação.

Use `ControllerTool` quando um método de controller já é uma fronteira limpa da aplicação e não depende tanto de middleware de rota.

Use `RouteTool` quando o ciclo da rota faz parte do comportamento: model binding, middleware, policies, form requests, throttling ou auditoria.

## 10. Notas De Produção

O servidor MCP valida o formato JSON-RPC 2.0, rejeita IDs `null`, ignora notificações sem retornar resposta, suporta batch requests e reporta falhas de validação ou execução da tool como resultados MCP com `isError: true`.

A capacidade atual do servidor é focada em tools. Resources, prompts, sampling e elicitation ficam fora deste slice até existir um caso de uso de primeira classe no runtime de agentes.
