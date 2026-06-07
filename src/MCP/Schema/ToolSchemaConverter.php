<?php

namespace Andmarruda\LaravelAgents\MCP\Schema;

use InvalidArgumentException;

class ToolSchemaConverter
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function toMcpInputSchema(array $schema): array
    {
        $normalized = $this->normalize($schema);

        if (($normalized['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('MCP tool schemas must have an object root type.');
        }

        $normalized['properties'] = is_array($normalized['properties'] ?? null)
            ? $normalized['properties']
            : [];

        return $normalized;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function normalize(array $schema): array
    {
        $allowed = [
            'type',
            'description',
            'properties',
            'required',
            'items',
            'enum',
            'default',
            'minimum',
            'maximum',
            'minLength',
            'maxLength',
            'minItems',
            'maxItems',
            'additionalProperties',
        ];

        $normalized = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $schema)) {
                $normalized[$key] = $schema[$key];
            }
        }

        if (! isset($normalized['type'])) {
            $normalized['type'] = 'object';
        }

        if (isset($normalized['properties'])) {
            if (! is_array($normalized['properties'])) {
                throw new InvalidArgumentException('Schema properties must be an array.');
            }

            $properties = [];
            foreach ($normalized['properties'] as $name => $propertySchema) {
                if (! is_array($propertySchema)) {
                    throw new InvalidArgumentException("Schema property [{$name}] must be an array.");
                }

                $properties[$name] = $this->normalize($propertySchema);
            }

            $normalized['properties'] = $properties;
        }

        if (isset($normalized['items'])) {
            if (! is_array($normalized['items'])) {
                throw new InvalidArgumentException('Schema items must be an array.');
            }

            $normalized['items'] = $this->normalize($normalized['items']);
        }

        if (isset($normalized['required']) && ! is_array($normalized['required'])) {
            throw new InvalidArgumentException('Schema required field must be an array.');
        }

        return $normalized;
    }
}
