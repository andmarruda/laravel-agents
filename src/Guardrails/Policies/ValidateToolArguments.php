<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Schema\JsonSchemaValidator;

class ValidateToolArguments implements ToolGuardrail
{
    public function __construct(protected ?JsonSchemaValidator $validator = null)
    {
        $this->validator ??= new JsonSchemaValidator();
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        if ($context->phase !== 'before') {
            return GuardrailResult::allow($value);
        }

        $schema = $context->get('tool_schema', ['type' => 'object']);
        $violations = is_array($schema) ? $this->validator->violations($schema, $value, 'input') : [];

        return $violations === [] ? GuardrailResult::allow($value) : GuardrailResult::deny($violations);
    }
}
