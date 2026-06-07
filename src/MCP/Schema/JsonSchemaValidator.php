<?php

namespace Andmarruda\LaravelAgents\MCP\Schema;

use InvalidArgumentException;

class JsonSchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     */
    public function validate(array $schema, array $input): void
    {
        $this->validateObject($schema, $input, 'input');
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $value
     */
    protected function validateObject(array $schema, array $value, string $path): void
    {
        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $value)) {
                throw new InvalidArgumentException("Missing required field [{$path}.{$required}].");
            }
        }

        foreach ($schema['properties'] ?? [] as $name => $propertySchema) {
            if (! array_key_exists($name, $value) || ! is_array($propertySchema)) {
                continue;
            }

            $this->validateValue($propertySchema, $value[$name], "{$path}.{$name}");
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    protected function validateValue(array $schema, mixed $value, string $path): void
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new InvalidArgumentException("Field [{$path}] must be one of the allowed values.");
        }

        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->matchesType((string) $type, $value)) {
            throw new InvalidArgumentException("Field [{$path}] must be of type [{$type}].");
        }

        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new InvalidArgumentException("Field [{$path}] must be at least [{$schema['minimum']}].");
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new InvalidArgumentException("Field [{$path}] must be at most [{$schema['maximum']}].");
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                throw new InvalidArgumentException("Field [{$path}] is shorter than [{$schema['minLength']}].");
            }

            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                throw new InvalidArgumentException("Field [{$path}] is longer than [{$schema['maxLength']}].");
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                throw new InvalidArgumentException("Field [{$path}] has fewer than [{$schema['minItems']}] items.");
            }

            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                throw new InvalidArgumentException("Field [{$path}] has more than [{$schema['maxItems']}] items.");
            }

            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    $this->validateValue($schema['items'], $item, "{$path}.{$index}");
                }
            }
        }

        if (is_array($value) && ! array_is_list($value) && ($schema['type'] ?? null) === 'object') {
            $this->validateObject($schema, $value, $path);
        }
    }

    protected function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ! array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
