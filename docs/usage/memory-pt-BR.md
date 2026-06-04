# Memória Persistente: Laravel Agents

Agentes são stateless por padrão. Cada chamada a `generate()` constrói uma lista de mensagens do zero e nada sobrevive entre requisições. A memória persistente muda isso de duas formas complementares:

- **Memória de curto prazo** mantém o histórico de conversa de uma sessão. Armazenada no Redis, com TTL configurável por agente.
- **Memória de longo prazo** guarda fatos e valores arbitrários indexados por agente, escopo e chave. Armazenada no banco de dados principal da aplicação.

Ambos os tipos seguem o mesmo limite de Ports & Adapters usado no restante do pacote, então os adapters podem ser trocados sem alterar o código dos agentes.

---

## Configuração

Publique e rode a migration para memória de longo prazo:

```bash
php artisan vendor:publish --tag=agents-migrations
php artisan migrate
```

Publique o config se ainda não tiver feito:

```bash
php artisan vendor:publish --tag=agents-config
```

Adicione as variáveis relevantes no `.env`:

```env
# Curto prazo (Redis)
AGENTS_REDIS_CONNECTION=default
AGENTS_MEMORY_PREFIX=agents:session:
AGENTS_MEMORY_TTL=3600

# Longo prazo (banco de dados)
AGENTS_DB_CONNECTION=
AGENTS_MEMORY_TABLE=agent_memories
```

`AGENTS_DB_CONNECTION` usa a conexão padrão da aplicação quando deixado em branco.

---

## Memória de Curto Prazo

A memória de curto prazo armazena o par de mensagens — user e assistant — após cada chamada a `generate()`. Quando a mesma sessão é retomada, o histórico completo é inserido no início da lista de mensagens, para que o modelo veja a conversa como contínua.

### Habilitando no agente

Chame `enableShortTermMemory()` dentro de `configure()`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ChatAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('chat');
        $this->instructions('Você é um assistente prestativo.');
        $this->model('openai/gpt-4.1-mini');
        $this->enableShortTermMemory();
    }
}
```

Por padrão o TTL da sessão é **3600 segundos** (uma hora). Para mudar por agente:

```php
$this->enableShortTermMemory();
$this->memoryTtl(1800); // 30 minutos
```

### Primeira chamada — o pacote cria a sessão

Na primeira chamada a `generate()`, o pacote gera um UUID e o expõe em `AgentResponse::$sessionId`. Guarde-o para poder retomar a conversa:

```php
use App\Agents\ChatAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ChatAgent::class)
    ->generate('Qual é a capital da França?');

$sessionId = $response->sessionId; // "550e8400-e29b-41d4-a716-446655440000"

echo $response->content; // "A capital da França é Paris."
```

### Chamadas seguintes — retomando a sessão

Passe o session ID de volta via `continueSession()`:

```php
$response = LaravelAgents::agent(ChatAgent::class)
    ->continueSession($sessionId)
    ->generate('E qual é o monumento mais famoso de lá?');

echo $response->content;
// "O monumento mais famoso de Paris é a Torre Eiffel."
```

O agente agora tem o par anterior no contexto, então entende que "lá" se refere a Paris.

### O session ID sempre é retornado

`continueSession()` também retorna um session ID, então você pode sempre atualizar o valor armazenado a partir da resposta:

```php
$sessionId = $response->sessionId;
```

### Onde guardar o session ID na aplicação

O session ID é apenas uma string. Guarde onde fizer mais sentido:

```php
// Em uma coluna Eloquent
$conversation->update(['session_id' => $response->sessionId]);

// Na sessão HTTP
session(['agent_session' => $response->sessionId]);

// Em cache indexado pelo usuário
Cache::put("chat:{$userId}", $response->sessionId, now()->addHour());
```

---

## Memória de Longo Prazo

A memória de longo prazo armazena fatos estruturados no banco de dados como pares chave-valor. Ela não injeta nada automaticamente — o desenvolvedor decide o que guardar e quando usar.

### Guardando um fato

Chame `remember(scope, key, value)` na instância do agente. `scope` identifica a quem a memória pertence (tipicamente o ID do usuário ou de uma entidade):

```php
$agent = LaravelAgents::agent(ChatAgent::class);

$agent->remember((string) $user->id, 'idioma', 'Português');
$agent->remember((string) $user->id, 'fuso_horario', 'America/Sao_Paulo');
```

`value` pode ser qualquer tipo serializável em JSON — string, número, array ou objeto.

### Recuperando fatos

Recuperar todos os fatos de um escopo:

```php
$fatos = $agent->recall((string) $user->id);
// ['idioma' => 'Português', 'fuso_horario' => 'America/Sao_Paulo']
```

Recuperar uma chave específica:

```php
$fatos = $agent->recall((string) $user->id, 'idioma');
// ['idioma' => 'Português']
```

### Removendo fatos

Remover uma chave específica:

```php
$agent->forget((string) $user->id, 'fuso_horario');
```

Remover tudo do escopo:

```php
$agent->forget((string) $user->id);
```

### Injetando fatos de longo prazo no contexto

Como a memória de longo prazo é controlada pelo desenvolvedor, injete os fatos via argumento `$context` ou diretamente nas instructions antes de gerar:

```php
$fatos = $agent->recall((string) $user->id);

$response = LaravelAgents::agent(ChatAgent::class)
    ->continueSession($sessionId)
    ->generate('Resuma minhas preferências.', [
        'preferencias_usuario' => $fatos,
    ]);
```

O system prompt do agente vai conter os fatos como JSON no bloco `Context:`.

---

## Combinando os Dois Tipos

Um padrão comum é usar memória de curto prazo para o fio da conversa e memória de longo prazo para preferências persistentes do usuário:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class AssistentePersonalAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('assistente');
        $this->instructions('Você é um assistente pessoal. Adapte-se às preferências do usuário no contexto.');
        $this->model('anthropic/claude-sonnet-4');
        $this->enableShortTermMemory();
        $this->memoryTtl(7200);
    }
}
```

```php
$agent = LaravelAgents::agent(AssistentePersonalAgent::class);

// Carrega fatos de longo prazo e passa como contexto
$fatos = $agent->recall((string) $user->id);

$response = $agent
    ->continueSession($sessionId)
    ->generate($mensagemUsuario, ['preferencias' => $fatos]);

// Persiste algo novo que o usuário mencionou
if ($usuarioMencionouNovaPreferencia) {
    $agent->remember((string) $user->id, 'idioma_preferido', 'Português');
}

$sessionId = $response->sessionId;
```

---

## Notas de Arquitetura

Ambos os tipos de memória são vinculados como singletons no container via contratos:

| Contrato | Adapter padrão |
|---|---|
| `ShortTermMemory` | `RedisShortTermAdapter` |
| `LongTermMemory` | `DatabaseLongTermAdapter` |

Para trocar um adapter, rebinde o contrato em qualquer service provider:

```php
use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;

$this->app->singleton(ShortTermMemory::class, function () {
    return new MeuAdapterPersonalizado();
});
```

O contrato `ShortTermMemory` exige três métodos: `append`, `messages` e `clear`.
O contrato `LongTermMemory` exige: `store`, `retrieve` e `forget`.
