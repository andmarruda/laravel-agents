<?php

namespace Andmarruda\LaravelAgents\Guardrails\Policies;

use Andmarruda\LaravelAgents\Guardrails\Contracts\InputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\OutputGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Contracts\ToolGuardrail;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailContext;
use Andmarruda\LaravelAgents\Guardrails\Data\GuardrailResult;
use Andmarruda\LaravelAgents\Guardrails\Data\Violation;
use Andmarruda\LaravelAgents\Schema\JsonSchemaValidator;

class JsonSchema implements InputGuardrail, OutputGuardrail, ToolGuardrail
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        protected array $schema,
        protected bool $decodeJson = false,
        protected bool $retry = true,
        protected ?JsonSchemaValidator $validator = null,
        protected array $operations = ['model.output'],
    ) {
        $this->validator ??= new JsonSchemaValidator();
    }

    public function evaluate(mixed $value, GuardrailContext $context): GuardrailResult
    {
        if (! in_array($context->operation, $this->operations, true)) {
            return GuardrailResult::allow($value);
        }

        if ($this->decodeJson && is_string($value)) {
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->failure([new Violation('invalid_json', 'Response must be valid JSON.')]);
            }
        }

        $violations = $this->validator->violations($this->schema, $value);

        return $violations === [] ? GuardrailResult::allow($value) : $this->failure($violations);
    }

    protected function failure(array $violations): GuardrailResult
    {
        return $this->retry ? GuardrailResult::retry($violations) : GuardrailResult::deny($violations);
    }
}
