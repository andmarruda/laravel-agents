<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Schema\JsonSchemaValidator;

class ValidateToolResult implements ToolGuardrail
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(protected array $schema, protected ?JsonSchemaValidator $validator = null)
    {
        $this->validator ??= new JsonSchemaValidator();
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        if ($context->phase !== 'after') {
            return GuardrailResult::allow($value);
        }

        $violations = $this->validator->violations($this->schema, $value, 'result');

        return $violations === [] ? GuardrailResult::allow($value) : GuardrailResult::deny($violations);
    }
}
