<?php

namespace Andmarruda\LaravelAgents\Guardrails\Data;

use Andmarruda\LaravelAgents\Guardrails\Enums\GuardrailDecision;

class GuardrailResult
{
    /**
     * @param array<int, Violation> $violations
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly GuardrailDecision $decision,
        public readonly mixed $value = null,
        public readonly array $violations = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function allow(mixed $value = null): static
    {
        return new static(GuardrailDecision::Allow, $value);
    }

    public static function modify(mixed $value, array $violations = []): static
    {
        return new static(GuardrailDecision::Modify, $value, $violations);
    }

    public static function deny(Violation|array $violations): static
    {
        return new static(GuardrailDecision::Deny, violations: self::violations($violations));
    }

    public static function retry(Violation|array $violations): static
    {
        return new static(GuardrailDecision::Retry, violations: self::violations($violations));
    }

    public static function requireApproval(Violation|array $violations, array $metadata = []): static
    {
        return new static(
            GuardrailDecision::RequireApproval,
            violations: self::violations($violations),
            metadata: $metadata,
        );
    }

    /**
     * @return array<int, Violation>
     */
    private static function violations(Violation|array $violations): array
    {
        return $violations instanceof Violation ? [$violations] : array_values($violations);
    }
}
