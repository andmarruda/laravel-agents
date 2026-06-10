<?php

namespace Andmarruda\LaravelAgents\Guardrails;

use Andmarruda\LaravelAgents\Guardrails\Approvals\ApprovalRequest;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ApprovalStore;
use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\PrioritizedGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Enums\ApprovalStatus;
use Andmarruda\LaravelAgents\Guardrails\Enums\GuardrailDecision;
use Andmarruda\LaravelAgents\Guardrails\Events\ApprovalRequested;
use Andmarruda\LaravelAgents\Guardrails\Events\GuardrailEvaluated;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\ApprovalRequiredException;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\GuardrailDeniedException;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\GuardrailValidationException;
use Andmarruda\LaravelAgents\Guardrails\Exceptions\InvalidGuardrailResultException;
use Andmarruda\LaravelAgents\Observability\TraceManager;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class GuardrailPipeline
{
    /**
     * @param array<int, object> $globalGuardrails
     */
    public function __construct(
        protected array $globalGuardrails = [],
        protected int $maxPolicies = 100,
        protected ?ApprovalStore $approvals = null,
        protected ?TraceManager $traces = null,
    ) {
    }

    /**
     * @param array<int, object> $guardrails
     */
    public function run(mixed $value, GuardrailContext $context, array $guardrails = []): GuardrailResult
    {
        $policies = array_values(array_filter(
            [...$this->globalGuardrails, ...$guardrails],
            fn (object $policy) => $this->applies($policy, $context),
        ));
        usort($policies, fn (object $a, object $b) => $this->priority($b) <=> $this->priority($a));

        if (count($policies) > $this->maxPolicies) {
            throw new RuntimeException("Guardrail pipeline exceeds the configured limit of {$this->maxPolicies} policies.");
        }

        $current = $value;
        $modified = false;
        $violations = [];

        foreach ($policies as $policy) {
            if (! method_exists($policy, 'evaluate')) {
                throw new InvalidGuardrailResultException('Guardrail ['.$policy::class.'] must define evaluate().');
            }

            $started = microtime(true);
            $span = $this->traces?->startSpan('guardrail.evaluate', 'guardrail', [
                'guardrail.policy' => $policy::class,
                'guardrail.operation' => $context->operation,
                'guardrail.phase' => $context->phase,
                'guardrail.attempt' => $context->attempt,
            ]);
            try {
                $result = $policy->evaluate($current, $context);

                if (! $result instanceof GuardrailResult) {
                    throw new InvalidGuardrailResultException('Guardrail ['.$policy::class.'] returned an invalid result.');
                }
            } catch (Throwable $throwable) {
                $this->traces?->failSpan($span, $throwable);

                throw $throwable;
            }

            $latency = (microtime(true) - $started) * 1000;
            $this->traces?->finishSpan($span, [
                'guardrail.decision' => $result->decision->value,
                'guardrail.latency_ms' => $latency,
                'guardrail.violation_codes' => array_map(fn ($v) => $v->code, $result->violations),
            ]);
            $this->traces?->dispatch(new GuardrailEvaluated($policy::class, $context, $result, $latency));
            $violations = [...$violations, ...$result->violations];

            if ($result->decision === GuardrailDecision::Modify) {
                $current = $result->value;
                $modified = true;
                continue;
            }

            if ($result->decision === GuardrailDecision::Deny) {
                foreach ($result->violations as $violation) {
                    if (str_starts_with($violation->code, 'schema.') || $violation->code === 'invalid_json') {
                        throw new GuardrailValidationException($result->violations);
                    }
                }

                throw new GuardrailDeniedException($result->violations);
            }

            if ($result->decision === GuardrailDecision::RequireApproval) {
                $this->requireApproval($current, $context, $result);
                continue;
            }

            if ($result->decision === GuardrailDecision::Retry) {
                return $result;
            }
        }

        return $modified
            ? GuardrailResult::modify($current, $violations)
            : new GuardrailResult(GuardrailDecision::Allow, $current, $violations);
    }

    protected function requireApproval(mixed $value, GuardrailContext $context, GuardrailResult $result): void
    {
        if (! $this->approvals) {
            throw new RuntimeException('An approval store is required for approval guardrails.');
        }

        $approvalId = $context->get('approval_id');
        if (is_string($approvalId)) {
            $approval = $this->approvals->get($approvalId);
            if ($approval?->status === ApprovalStatus::Approved) {
                $this->approvals->consume($approvalId, $value);

                return;
            }
        }

        $approval = ApprovalRequest::create(
            $context->tool ?? $context->operation,
            $value,
            isset($result->metadata['expires_at'])
                ? new DateTimeImmutable((string) $result->metadata['expires_at'])
                : null,
            metadata: $result->metadata,
        );
        $this->approvals->put($approval);
        $this->traces?->dispatch(new ApprovalRequested($approval));

        throw new ApprovalRequiredException($approval, $result->violations);
    }

    protected function priority(object $guardrail): int
    {
        return $guardrail instanceof PrioritizedGuardrail ? $guardrail->priority() : 0;
    }

    protected function applies(object $guardrail, GuardrailContext $context): bool
    {
        return match ($context->operation) {
            'model.input' => $guardrail instanceof InputGuardrail,
            'model.output' => $guardrail instanceof OutputGuardrail,
            'tool' => $guardrail instanceof ToolGuardrail,
            default => true,
        };
    }
}
