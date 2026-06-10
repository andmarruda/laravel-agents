# Guardrails

Guardrails fornecem controles centralizados antes e depois de chamadas de modelo e execução de ferramentas. Eles suportam políticas globais, por agente e por execução, com decisões `allow`, `deny`, `modify`, `retry` e `require_approval`.

## Políticas No Agente

Configure políticas dentro do agente:

```php
use Andmarruda\LaravelAgents\Guardrails\Policies\AllowedTools;
use Andmarruda\LaravelAgents\Guardrails\Policies\JsonSchema;
use Andmarruda\LaravelAgents\Guardrails\Policies\MaxInputLength;
use Andmarruda\LaravelAgents\Guardrails\Policies\RedactSensitiveData;
use Andmarruda\LaravelAgents\Guardrails\Policies\RequireApproval;

public function configure(): void
{
    $this->guardrails([
        new MaxInputLength(10_000),
        new RedactSensitiveData(),
        new JsonSchema($outputSchema, decodeJson: true),
    ]);

    $this->toolGuardrails([
        new AllowedTools(['retrieve_context', 'delete_customer']),
        new RequireApproval(['delete_customer'], ttlSeconds: 900),
    ]);

    $this->corrections(maxAttempts: 3, backoffMilliseconds: 100);
}
```

Adicione contexto e políticas por execução:

```php
$response = LaravelAgents::agent(SupportAgent::class)
    ->withGuardrailContext([
        'user_id' => 10,
        'tenant_id' => 20,
        'resource_id' => 30,
    ])
    ->withRunGuardrails([$policy])
    ->withRunToolGuardrails([$toolPolicy])
    ->generate($input);
```

Use `ContextPolicy` para autorizar com base em usuário, tenant, recurso, metadados de runtime, nome/classe da ferramenta e input.

## Políticas Globais

Adicione classes ou instâncias em `agents.guardrails.global`. Os contratos compatíveis são selecionados automaticamente para input/output do modelo e ferramentas. Por padrão, argumentos de ferramentas são validados pelo JSON Schema declarado.

O pipeline executa políticas por prioridade, interrompe decisões de bloqueio/retry/aprovação e rejeita mais políticas que `agents.guardrails.max_policies`.

## Output Estruturado E Correção

`JsonSchema` retorna violações detalhadas por campo. Com `decodeJson: true`, JSON malformado e schema inválido iniciam correções limitadas. O prompt de correção contém somente códigos, paths e mensagens necessários. A metadata final contém `correction_attempts`.

## Aprovação Humana

Políticas para ferramentas sensíveis lançam `ApprovalRequiredException`. Persista a solicitação, aprove ou negue via `ApprovalStore` e tente novamente com o mesmo payload imutável:

```php
try {
    $agent->generate($input);
} catch (ApprovalRequiredException $exception) {
    $approvalId = $exception->approval->id;
}

$approvals->approve($approvalId);

$response = $agent
    ->withGuardrailContext(['approval_id' => $approvalId])
    ->generate($sameInput);
```

Aprovações podem estar pending, approved, denied, expired ou consumed. O consumo é atômico, de uso único e verifica o fingerprint do payload. Configure `AGENTS_APPROVAL_STORE=database` e publique `agents-migrations` para persistência.

Workflows podem usar o mesmo store:

```php
$workflow->setApprovalStore($approvals);
$suspended = $workflow->run($input, store: $snapshots);
$approvals->approve($suspended->snapshot->approval['approval_id']);
$workflow->resumeWithApproval($suspended->snapshot, $approvalId, $snapshots);
```

## Políticas Customizadas E Observabilidade

Implemente `InputGuardrail`, `OutputGuardrail` ou `ToolGuardrail` e retorne um `GuardrailResult`. Implemente `PrioritizedGuardrail` quando a ordem for relevante.

Cada avaliação cria um span `guardrail.evaluate` com política, operação, fase, decisão, latência e códigos seguros de violação. Os eventos Laravel incluem `GuardrailEvaluated`, `GuardrailRetrying`, `ApprovalRequested` e `ApprovalStatusChanged`. Chaves de atributos configuradas em `agents.guardrails.redact_keys` são ocultadas antes do armazenamento/export.
