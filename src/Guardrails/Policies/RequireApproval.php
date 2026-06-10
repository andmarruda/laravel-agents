<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use DateTimeImmutable;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;

class RequireApproval implements ToolGuardrail
{
    /**
     * @param array<int, string> $tools
     */
    public function __construct(protected array $tools, protected ?int $ttlSeconds = null)
    {
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        if ($context->phase !== 'before' || ! in_array($context->tool, $this->tools, true)) {
            return GuardrailResult::allow($value);
        }

        return GuardrailResult::requireApproval(
            new Violation('approval_required', 'This tool call requires human approval.', metadata: ['tool' => $context->tool]),
            array_filter([
                'tool' => $context->tool,
                'expires_at' => $this->ttlSeconds === null
                    ? null
                    : (new DateTimeImmutable("+{$this->ttlSeconds} seconds"))->format(DATE_ATOM),
            ]),
        );
    }
}
