<?php

namespace Andmarruda\LaravelAgents\Schema;

use Andmarruda\LaravelAgents\Guardrails\Data\Violation;
use InvalidArgumentException;

class JsonSchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @return array<int, Violation>
     */
    public function violations(array $schema, mixed $value, string $path = 'value'): array
    {
        $violations = [];
        $this->validateValue($schema, $value, $path, $violations);

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function validate(array $schema, mixed $value, string $path = 'input'): void
    {
        $violations = $this->violations($schema, $value, $path);

        if ($violations !== []) {
            throw new InvalidArgumentException($violations[0]->message);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, Violation> $violations
     */
    protected function validateValue(array $schema, mixed $value, string $path, array &$violations): void
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $violations[] = new Violation('schema.enum', "Field [{$path}] must be one of the allowed values.", $path);
        }

        $types = is_array($schema['type'] ?? null) ? $schema['type'] : [($schema['type'] ?? null)];
        $types = array_values(array_filter($types, 'is_string'));
        if ($types !== [] && ! $this->matchesAnyType($types, $value)) {
            $violations[] = new Violation('schema.type', "Field [{$path}] must be of type [".implode('|', $types).'].', $path);

            return;
        }

        if (is_array($value) && (($schema['type'] ?? null) === 'object' || ! array_is_list($value))) {
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $field = "{$path}.{$required}";
                    $violations[] = new Violation('schema.required', "Missing required field [{$field}].", $field);
                }
            }

            foreach ($schema['properties'] ?? [] as $name => $propertySchema) {
                if (array_key_exists($name, $value) && is_array($propertySchema)) {
                    $this->validateValue($propertySchema, $value[$name], "{$path}.{$name}", $violations);
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $name) {
                    $violations[] = new Violation('schema.additional_property', "Unexpected field [{$path}.{$name}].", "{$path}.{$name}");
                }
            }
        }

        if (is_array($value) && array_is_list($value)) {
            $this->checkRange($schema, count($value), $path, 'Items', 'minItems', 'maxItems', $violations);
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    $this->validateValue($schema['items'], $item, "{$path}.{$index}", $violations);
                }
            }
        }

        if (is_string($value)) {
            $this->checkRange($schema, strlen($value), $path, 'Length', 'minLength', 'maxLength', $violations);
            if (
                isset($schema['pattern'])
                && @preg_match('~'.str_replace('~', '\~', (string) $schema['pattern']).'~', $value) !== 1
            ) {
                $violations[] = new Violation('schema.pattern', "Field [{$path}] does not match the required pattern.", $path);
            }
        }

        if (is_numeric($value)) {
            $this->checkRange($schema, $value, $path, 'Value', 'minimum', 'maximum', $violations);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, Violation> $violations
     */
    protected function checkRange(array $schema, int|float $value, string $path, string $label, string $min, string $max, array &$violations): void
    {
        if (isset($schema[$min]) && $value < $schema[$min]) {
            $violations[] = new Violation('schema.'.$min, "{$label} for [{$path}] must be at least [{$schema[$min]}].", $path);
        }
        if (isset($schema[$max]) && $value > $schema[$max]) {
            $violations[] = new Violation('schema.'.$max, "{$label} for [{$path}] must be at most [{$schema[$max]}].", $path);
        }
    }

    protected function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<int, string> $types
     */
    protected function matchesAnyType(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            if ($this->matchesType($type, $value)) {
                return true;
            }
        }

        return false;
    }
}
