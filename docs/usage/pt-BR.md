# Guia De Uso: Laravel Agents

Este guia mostra como testar a implementação alpha atual em um projeto Laravel.

O foco desta versão é o core de orquestração:

- chamar modelos por `provider/model`;
- criar agentes workers;
- criar um agente supervisor/manager;
- delegar tarefas entre agentes;
- executar workflows determinísticos para processos de negócio explícitos;
- receber resposta final e histórico de passos.

Para uso com MCP, veja [Guia de Uso: MCP](mcp-pt-BR.md). Ele cobre clientes MCP remotos, servidor MCP em Laravel, adapters para controllers e rotas, hooks de autenticação e permissões MCP por agente.

Para retrieval-augmented generation, veja [Guia de Uso: RAG](rag-pt-BR.md). Ele cobre document loaders, chunking, embeddings, pgvector, Qdrant, ferramentas de retrieval e steps para workflows.

Para controles de segurança e autorização, veja [Guardrails](guardrails-pt-BR.md). Ele cobre políticas, validação de schema, retries de correção, permissões de ferramentas, aprovações e observabilidade.

## 1. Instalação

Se o pacote já estiver disponível via Composer/Packagist, rode em um projeto Laravel:

```bash
composer require andmarruda/laravel-agents
```

Para teste alpha local, adicione um path repository no `composer.json` do seu app Laravel:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-agents",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Depois instale:

```bash
composer require andmarruda/laravel-agents:@dev
```

Publique a configuração:

```bash
php artisan vendor:publish --tag=agents-config
```

Publique as migrations quando quiser memória ou observabilidade com banco de dados:

```bash
php artisan vendor:publish --tag=agents-migrations
php artisan migrate
```

Configure o `.env`:

```env
AGENTS_DEFAULT_MODEL=openai/gpt-4.1-mini
AGENTS_MODEL_TIMEOUT=60
AGENTS_MODEL_RETRY_TIMES=2
AGENTS_MODEL_RETRY_SLEEP=250
AGENTS_IMAGE_MODEL=openai/gpt-image-1
AGENTS_IMAGE_SIZE=1024x1024
AGENTS_IMAGE_DISK=public

OPENAI_API_KEY=
ANTHROPIC_API_KEY=
FIREWORKS_API_KEY=
```

Você só precisa preencher a chave do provider que for usar primeiro.

## 1.1. Observabilidade

Observabilidade é opt-in e armazena traces no banco quando estiver habilitada e as migrations estiverem instaladas. Os traces incluem execuções de agentes, chamadas de modelo, chamadas de ferramentas, delegação do supervisor, workflows, nós de workflow, uso de tokens, latência, provider, modelo e metadados opcionais de custo.

```env
AGENTS_OBSERVABILITY_ENABLED=true
AGENTS_OBSERVABILITY_STORE=database
AGENTS_OBSERVABILITY_DASHBOARD_ENABLED=true
AGENTS_OBSERVABILITY_DASHBOARD_ROUTE=/agents/observability/traces
```

As respostas incluem o id do trace:

```php
$response = LaravelAgents::agent(ResearchAgent::class)->generate('Pesquise isto.');

$traceId = $response->meta['trace_id'];
```

Com o dashboard habilitado, use estes endpoints JSON:

```text
GET /agents/observability/traces
GET /agents/observability/traces/{traceId}
```

Eventos Laravel são disparados para hooks de ciclo de vida de agentes, modelos, ferramentas e workflows. Para telemetria externa, registre sua própria implementação de `TraceExporter` ou adapte `OpenTelemetryTraceExporter`.

## 2. Testando Um Modelo Diretamente

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::model('openai/gpt-4.1-mini')->generate([
    ['role' => 'user', 'content' => 'Responda em uma frase: o que é um agente de IA?'],
]);

echo $response->content;
```

Exemplos de nomes de modelos:

```php
LaravelAgents::model('openai/gpt-4.1-mini');
LaravelAgents::model('anthropic/claude-sonnet-4');
LaravelAgents::model('fireworks/accounts/fireworks/models/llama-v3p1-70b-instruct');
```

## 2.1. Gerando Imagens

Imagem é tratada como capability, não como resposta de texto.

```php
use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::image('openai/gpt-image-1')
    ->generate(new ImageGenerationRequest(
        prompt: 'Um diagrama limpo de orquestração de agentes em Laravel',
        size: '1024x1024',
    ));

$url = $response->firstUrl();
$base64 = $response->firstBase64();
```

## 3. Criando Um Worker Agent

Crie `app/Agents/ResearchAgent.php`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ResearchAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('researcher');
        $this->description('Pesquisa fatos, restrições e contexto útil.');
        $this->instructions('Você é um agente de pesquisa cuidadoso. Seja objetivo e indique incertezas.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

Use o agente:

```php
use App\Agents\ResearchAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ResearchAgent::class)
    ->generate('Pesquise os trade-offs de usar agentes de IA em uma aplicação Laravel.');

echo $response->content;
```

## 4. Criando Workers Adicionais

Exemplo de writer:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class WriterAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('writer');
        $this->description('Transforma notas e contexto em texto claro.');
        $this->instructions('Escreva de forma clara, prática e direta.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

Exemplo de reviewer:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ReviewerAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('reviewer');
        $this->description('Revisa respostas em busca de lacunas, riscos e inconsistências.');
        $this->instructions('Revise criticamente. Aponte problemas e sugira melhorias.');
        $this->model('openai/gpt-4.1-mini');
    }
}
```

## 5. Criando Um Supervisor Agent

Crie `app/Agents/ManagerAgent.php`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\SupervisorAgent;

final class ManagerAgent extends SupervisorAgent
{
    public function configure(): void
    {
        $this->nameAs('manager');
        $this->description('Coordena agentes especialistas e produz a resposta final.');
        $this->instructions('Escolha o melhor worker para cada etapa. Pare quando a tarefa estiver completa.');
        $this->model('anthropic/claude-sonnet-4');
        $this->maxSteps(8);
        $this->withAgents([
            ResearchAgent::class,
            WriterAgent::class,
            ReviewerAgent::class,
        ]);
    }
}
```

Use o supervisor:

```php
use App\Agents\ManagerAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ManagerAgent::class)
    ->generate('Pesquise, escreva e revise um plano de lançamento para um pacote Laravel de agentes de IA.');

echo $response->content;

foreach ($response->steps as $step) {
    logger()->info('Agent step', $step);
}
```

## 6. Como O Supervisor Decide

A cada rodada, o supervisor pede ao modelo uma decisão em JSON estrito.

Para delegar:

```json
{"action":"delegate","agent":"researcher","task":"Pesquise o contexto de mercado"}
```

Para finalizar:

```json
{"action":"final","answer":"Resposta final aqui"}
```

Se o modelo retornar JSON inválido, o pacote lança uma exceção. Nesta fase alpha, isso é esperado: guardrails e retry/correction loops entram em uma versão futura.

## 7. Passando Contexto

```php
$response = LaravelAgents::agent(ManagerAgent::class)
    ->generate('Analise este projeto alpha.', [
        'project_id' => $project->id,
        'user_id' => $user->id,
        'constraints' => [
            'sem memory persistente ainda',
            'workflows rodam de forma síncrona nesta alpha',
        ],
    ]);
```

No supervisor, esse contexto também é enviado para os workers junto com `previous_steps`.

## 8. Usando Opções Do Modelo

Dentro do agente:

```php
$this->options([
    'temperature' => 0.2,
    'max_tokens' => 1200,
]);
```

As opções `timeout`, `retry_times` e `retry_sleep` são tratadas como runtime config e não são enviadas no payload do modelo.

## 8.1. Executando Tools

Agentes com tools podem pedir uma ação real retornando JSON estrito:

```json
{"action":"tool","tool":"generate_image","input":{"prompt":"Imagem para post de lançamento","size":"1024x1024"}}
```

O agent executa a tool, injeta o resultado na próxima chamada do modelo e continua até gerar uma resposta final normal ou atingir o limite de tool steps.

## 9. Workflows Determinísticos

Use workflows quando você já conhece o processo e quer execução previsível. Um workflow ainda pode chamar agents ou tools dentro de uma etapa, mas a rota em si é definida pelo seu código.

```php
use Andmarruda\LaravelAgents\Facades\LaravelAgents;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;

$response = LaravelAgents::workflow()
    ->named('invoice-review')
    ->then('normalize', function (array $invoice, WorkflowContext $context): array {
        $context->put('currency', 'USD');

        return [
            ...$invoice,
            'total' => (float) $invoice['total'],
        ];
    })
    ->branch(
        fn (array $invoice): string => $invoice['total'] >= 1000 ? 'approval' : 'auto',
        [
            'approval' => fn (array $invoice): array => [...$invoice, 'status' => 'waiting_approval'],
            'auto' => fn (array $invoice): array => [...$invoice, 'status' => 'approved'],
        ],
        'approval_gate',
    )
    ->run(['id' => 10, 'total' => '1250.00']);

$invoice = $response->data;
$steps = $response->steps;
$context = $response->meta['context'];
```

A resposta contém:

- `data`: saída final do workflow;
- `steps`: histórico ordenado de steps, branches, loops e fan-out;
- `meta`: nome do workflow, quantidade de steps e contexto final.

## 9.1. Classes De Step Reutilizáveis

Para steps reutilizáveis, implemente `Step`:

```php
<?php

namespace App\Workflows\Steps;

use Andmarruda\LaravelAgents\Workflows\Step;
use Andmarruda\LaravelAgents\Workflows\WorkflowContext;

final class AddTaxStep implements Step
{
    public function handle(mixed $input, WorkflowContext $context): array
    {
        return [
            ...$input,
            'total' => $input['total'] * 1.08,
        ];
    }
}
```

Depois adicione ao workflow:

```php
use App\Workflows\Steps\AddTaxStep;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::workflow()
    ->then(AddTaxStep::class)
    ->run(['total' => 100]);
```

## 9.2. Helpers De Fluxo

```php
LaravelAgents::workflow()
    ->then('normalize', fn (array $input) => $input)
    ->parallel([
        'summary' => fn (array $input) => summarize($input),
        'score' => fn (array $input) => score($input),
    ])
    ->loopUntil(
        fn (array $state) => pollStatus($state),
        fn (array $state) => $state['ready'] === true,
        maxIterations: 5,
    )
    ->forEach(
        fn (array $state) => $state['items'],
        fn (array $item) => processItem($item),
    );
```

- `then()` executa um step.
- `branch()` escolhe um caminho nomeado.
- `parallel()` faz fan-out/fan-in em ordem de declaração.
- `loopUntil()` repete um step até a condição ser verdadeira ou atingir o limite de iterações.
- `forEach()` processa itens iteráveis e retorna resultados com as mesmas chaves.
- `approval()` suspende um workflow até uma aprovação humana ser enviada no resume.

## 9.3. Schemas, Snapshots E Aprovação

Workflows podem validar arrays de input e output com regras simples:

```php
$response = LaravelAgents::workflow()
    ->inputSchema(['name' => 'required|string'])
    ->outputSchema(['message' => 'required|string'])
    ->then('greet', fn (array $input): array => ['message' => 'Hello '.$input['name']])
    ->run(['name' => 'Ana']);
```

Steps de aprovação suspendem a execução e retornam um `WorkflowSnapshot`:

```php
use Andmarruda\LaravelAgents\Workflows\InMemoryWorkflowStore;

$store = new InMemoryWorkflowStore();

$workflow = LaravelAgents::workflow()
    ->then('prepare', fn (array $input): array => [...$input, 'prepared' => true])
    ->approval('manager_approval', 'Approve this invoice?', ['role' => 'manager'])
    ->then('finish', fn (array $input): array => [...$input, 'finished' => true]);

$response = $workflow->run(['id' => 10], store: $store);

$resumed = $workflow->resume(
    $response->snapshot->id,
    ['approved_by' => auth()->id()],
    $store,
);
```

A resposta suspensa tem `status`, `snapshot`, `steps` e metadados de aprovação em `meta`.

## 9.4. Execução Em Queue

Workflows baseados em classe podem ser despachados como jobs da queue do Laravel:

```php
final class InvoiceWorkflow extends \Andmarruda\LaravelAgents\Workflows\Workflow
{
    public function __construct()
    {
        parent::__construct('invoice-workflow');

        $this
            ->then(NormalizeInvoice::class)
            ->approval('manager_approval', 'Approve this invoice?')
            ->then(SaveInvoice::class);
    }
}

(new InvoiceWorkflow())->dispatch(['id' => 10]);
```

O job gerado implementa o contrato `ShouldQueue` do Laravel. Steps com closure são melhores para execução síncrona; steps em classe são mais seguros para serialização em queue.

## 10. Arquitetura

O pacote usa Ports & Adapters para provedores de modelo:

- porta de texto: `Andmarruda\LaravelAgents\Ports\ModelPort`;
- porta de imagem: `Andmarruda\LaravelAgents\Ports\ImageGenerationPort`;
- adapters: `OpenAiModelAdapter`, `AnthropicModelAdapter`, `FireworksModelAdapter`;
- composição: `ModelRouter`, `ImageRouter` e `AgentKernel`.

O resto do pacote deve evoluir por módulos pequenos:

- `MemoryPort`;
- `WorkflowStorePort`;
- `McpClientPort`;
- `TracePort`;
- `EmbeddingPort`;
- `VectorStorePort`;
- `GuardrailPort`.

Leia também: [../architecture/evolutionary-architecture.md](../architecture/evolutionary-architecture.md).

## 11. Limitações Da Versão Alpha

- Não há memory persistente.
- `InMemoryWorkflowStore` é para testes e exemplos. Apps em produção devem vincular um workflow store persistente.
- Steps de workflow com closure são melhores para execução síncrona; workflows em classe são mais seguros para queue.
- Não há streaming.
- Imagem tem adapter OpenAI nesta primeira versão.
- O supervisor depende de JSON válido vindo do modelo.

O objetivo agora é testar a ergonomia do core em um projeto Laravel real antes de adicionar os módulos mais pesados.
