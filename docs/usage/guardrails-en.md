# Guardrails

Guardrails provide centralized controls before and after model calls and tool execution. They support global, per-agent, and per-run policies with `allow`, `deny`, `modify`, `retry`, and `require_approval` decisions.

## Agent Policies

Configure policies inside an agent:

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

Add runtime context and policies:

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

Use `ContextPolicy` for authorization based on user, tenant, resource, runtime metadata, tool name, tool class, and input.

## Global Policies

Add guardrail classes or instances to `agents.guardrails.global`. Matching contracts are selected automatically for model input, model output, and tools. Tool arguments are JSON Schema validated globally by default.

The pipeline executes policies by priority, short-circuits deny/retry/approval decisions, and rejects more than `agents.guardrails.max_policies`.

## Structured Output And Correction

`JsonSchema` returns field-level violations. With `decodeJson: true`, malformed JSON and invalid schema output trigger bounded correction calls. Correction prompts include only validation codes, paths, and messages. The final response metadata contains `correction_attempts`.

## Human Approval

Sensitive tool policies throw `ApprovalRequiredException`. Persist the included request, approve or deny it through `ApprovalStore`, then retry with the same immutable payload:

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

Approvals can be pending, approved, denied, expired, or consumed. Consumption is atomic, single-use, and verifies the payload fingerprint. Configure `AGENTS_APPROVAL_STORE=database` and publish `agents-migrations` for persistent approvals.

Workflows can use the same store:

```php
$workflow->setApprovalStore($approvals);
$suspended = $workflow->run($input, store: $snapshots);
$approvals->approve($suspended->snapshot->approval['approval_id']);
$workflow->resumeWithApproval($suspended->snapshot, $approvalId, $snapshots);
```

## Custom Policies And Observability

Implement `InputGuardrail`, `OutputGuardrail`, or `ToolGuardrail` and return a `GuardrailResult`. Implement `PrioritizedGuardrail` when ordering matters.

Each evaluation creates a `guardrail.evaluate` span with policy, operation, phase, decision, latency, and safe violation codes. Laravel events include `GuardrailEvaluated`, `GuardrailRetrying`, `ApprovalRequested`, and `ApprovalStatusChanged`. Trace attribute keys configured in `agents.guardrails.redact_keys` are redacted before storage/export.
