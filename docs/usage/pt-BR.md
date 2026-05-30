# Guia De Uso: Laravel Agents

Este guia mostra como testar a implementação alpha atual em um projeto Laravel.

O foco desta versão é o core de orquestração:

- chamar modelos por `provider/model`;
- criar agentes workers;
- criar um agente supervisor/manager;
- delegar tarefas entre agentes;
- receber resposta final e histórico de passos.

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

Configure o `.env`:

```env
AGENTS_DEFAULT_MODEL=openai/gpt-4.1-mini
AGENTS_MODEL_TIMEOUT=60
AGENTS_MODEL_RETRY_TIMES=2
AGENTS_MODEL_RETRY_SLEEP=250

OPENAI_API_KEY=
ANTHROPIC_API_KEY=
FIREWORKS_API_KEY=
```

Você só precisa preencher a chave do provider que for usar primeiro.

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
            'sem workflows ainda',
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

## 9. Arquitetura

O pacote usa Ports & Adapters para provedores de modelo:

- porta: `Andmarruda\LaravelAgents\Ports\ModelPort`;
- adapters: `OpenAiModelAdapter`, `AnthropicModelAdapter`, `FireworksModelAdapter`;
- composição: `ModelRouter`.

O resto do pacote deve evoluir por módulos pequenos:

- `MemoryPort`;
- `WorkflowStorePort`;
- `McpClientPort`;
- `TracePort`;
- `EmbeddingPort`;
- `VectorStorePort`;
- `GuardrailPort`.

Leia também: [../architecture/evolutionary-architecture.md](../architecture/evolutionary-architecture.md).

## 10. Limitações Da Versão Alpha

- Não há memory persistente.
- Não há workflows.
- Não há streaming.
- Não há execução automática de tool calls.
- Não há fake provider para testes.
- O supervisor depende de JSON válido vindo do modelo.

O objetivo agora é testar a ergonomia do core em um projeto Laravel real antes de adicionar os módulos mais pesados.
