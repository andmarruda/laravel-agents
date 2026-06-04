# Persistent Memory: Laravel Agents

Agents are stateless by default. Each `generate()` call builds a fresh message list from scratch and nothing survives between requests. Persistent memory changes that in two complementary ways:

- **Short-term memory** keeps the conversation history for a session. Backed by Redis, with a configurable TTL per agent.
- **Long-term memory** stores arbitrary facts and values keyed by agent, scope, and key. Backed by the application's main database.

Both types follow the same Ports & Adapters boundary used elsewhere in the package, so the adapters can be swapped without touching agent code.

---

## Setup

Publish and run the migration for long-term memory:

```bash
php artisan vendor:publish --tag=agents-migrations
php artisan migrate
```

Publish the config if you have not done so yet:

```bash
php artisan vendor:publish --tag=agents-config
```

Add the relevant variables to your `.env`:

```env
# Short-term (Redis)
AGENTS_REDIS_CONNECTION=default
AGENTS_MEMORY_PREFIX=agents:session:
AGENTS_MEMORY_TTL=3600

# Long-term (database)
AGENTS_DB_CONNECTION=
AGENTS_MEMORY_TABLE=agent_memories
```

`AGENTS_DB_CONNECTION` defaults to the application's default database connection when left empty.

---

## Short-Term Memory

Short-term memory stores the exchange — user message and assistant reply — after every `generate()` call. When the same session is resumed, the full history is prepended to the message list, so the model sees the conversation as continuous.

### Enabling it in an agent

Call `enableShortTermMemory()` inside `configure()`:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class ChatAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('chat');
        $this->instructions('You are a helpful assistant.');
        $this->model('openai/gpt-4.1-mini');
        $this->enableShortTermMemory();
    }
}
```

By default the session TTL is **3600 seconds** (one hour). Override it per agent:

```php
$this->enableShortTermMemory();
$this->memoryTtl(1800); // 30 minutes
```

### First call — the package creates the session

On the first `generate()` call the package generates a UUID and stores it in `AgentResponse::$sessionId`. Save it so you can resume the conversation later:

```php
use App\Agents\ChatAgent;
use Andmarruda\LaravelAgents\Facades\LaravelAgents;

$response = LaravelAgents::agent(ChatAgent::class)
    ->generate('What is the capital of France?');

$sessionId = $response->sessionId; // "550e8400-e29b-41d4-a716-446655440000"

echo $response->content; // "The capital of France is Paris."
```

### Subsequent calls — resume the session

Pass the session ID back via `continueSession()`:

```php
$response = LaravelAgents::agent(ChatAgent::class)
    ->continueSession($sessionId)
    ->generate('And what is the most famous landmark there?');

echo $response->content;
// "The most famous landmark in Paris is the Eiffel Tower."
```

The agent now has the previous exchange in context, so it understands "there" refers to Paris.

### Session ID is always returned

`continueSession()` also returns a session ID, so you can always update the stored value from the response:

```php
$sessionId = $response->sessionId;
```

### Storing the session ID in your application

The session ID is just a string. Store it wherever makes sense:

```php
// In an Eloquent model column
$conversation->update(['session_id' => $response->sessionId]);

// In the HTTP session
session(['agent_session' => $response->sessionId]);

// In a cache entry keyed by user
Cache::put("chat:{$userId}", $response->sessionId, now()->addHour());
```

---

## Long-Term Memory

Long-term memory stores structured key-value facts in the database. It does not inject anything automatically — the developer decides what to store and when to use it.

### Storing a fact

Call `remember(scope, key, value)` on the agent instance. `scope` identifies who the memory belongs to (typically a user ID or entity ID):

```php
$agent = LaravelAgents::agent(ChatAgent::class);

$agent->remember((string) $user->id, 'language', 'Portuguese');
$agent->remember((string) $user->id, 'timezone', 'America/Sao_Paulo');
```

`value` can be any JSON-serializable type — string, number, array, or object.

### Retrieving facts

Retrieve all facts for a scope:

```php
$facts = $agent->recall((string) $user->id);
// ['language' => 'Portuguese', 'timezone' => 'America/Sao_Paulo']
```

Retrieve a single key:

```php
$facts = $agent->recall((string) $user->id, 'language');
// ['language' => 'Portuguese']
```

### Deleting facts

Delete a specific key:

```php
$agent->forget((string) $user->id, 'timezone');
```

Delete everything stored for a scope:

```php
$agent->forget((string) $user->id);
```

### Injecting long-term facts into context

Since long-term memory is developer-controlled, inject facts via the `$context` argument or inline into the instructions before generating:

```php
$facts = $agent->recall((string) $user->id);

$response = LaravelAgents::agent(ChatAgent::class)
    ->continueSession($sessionId)
    ->generate('Summarize my preferences.', [
        'user_facts' => $facts,
    ]);
```

The agent's system prompt will contain the facts as JSON under the `Context:` block.

---

## Combining Both Types

A common pattern is to use short-term memory for the conversation thread and long-term memory for persistent user preferences:

```php
<?php

namespace App\Agents;

use Andmarruda\LaravelAgents\Agents\Agent;

final class PersonalAssistantAgent extends Agent
{
    public function configure(): void
    {
        $this->nameAs('assistant');
        $this->instructions('You are a personal assistant. Adapt to the user preferences in context.');
        $this->model('anthropic/claude-sonnet-4');
        $this->enableShortTermMemory();
        $this->memoryTtl(7200);
    }
}
```

```php
$agent = LaravelAgents::agent(PersonalAssistantAgent::class);

// Load long-term facts and pass as context
$facts = $agent->recall((string) $user->id);

$response = $agent
    ->continueSession($sessionId)
    ->generate($userMessage, ['user_preferences' => $facts]);

// Persist something new the user mentioned
if ($userMentionedNewPreference) {
    $agent->remember((string) $user->id, 'preferred_language', 'English');
}

$sessionId = $response->sessionId;
```

---

## Architecture Notes

Both memory types are bound as singletons in the service container via contracts:

| Contract | Default adapter |
|---|---|
| `ShortTermMemory` | `RedisShortTermAdapter` |
| `LongTermMemory` | `DatabaseLongTermAdapter` |

To swap an adapter, rebind the contract in any service provider:

```php
use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;

$this->app->singleton(ShortTermMemory::class, function () {
    return new MyCustomShortTermAdapter();
});
```

The `ShortTermMemory` contract requires three methods: `append`, `messages`, and `clear`.
The `LongTermMemory` contract requires: `store`, `retrieve`, and `forget`.
