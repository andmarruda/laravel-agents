<?php

namespace Andmarruda\LaravelAgents\RAG\Metadata;

use Andmarruda\LaravelAgents\RAG\Contracts\MetadataSchema;
use Andmarruda\LaravelAgents\RAG\Data\MetadataNormalizer;
use InvalidArgumentException;

class StrictMetadataSchema implements MetadataSchema
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        protected array $fields = [],
        protected bool $allowUnknown = true,
        protected bool $allowNested = false,
    ) {
    }

    public function validate(array $metadata): array
    {
        $metadata = MetadataNormalizer::normalize($metadata);

        foreach ($metadata as $key => $value) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $key)) {
                throw new InvalidArgumentException("RAG metadata key [{$key}] is invalid.");
            }

            if (! $this->allowNested && (is_array($value) || is_object($value))) {
                throw new InvalidArgumentException("RAG metadata [{$key}] must be scalar or null.");
            }

            $expected = $this->fields[$key] ?? null;
            if ($expected === null && ! $this->allowUnknown) {
                throw new InvalidArgumentException("RAG metadata key [{$key}] is not allowed.");
            }

            if ($expected !== null && ! $this->matches($value, $expected)) {
                throw new InvalidArgumentException("RAG metadata [{$key}] must be of type [{$expected}].");
            }
        }

        return $metadata;
    }

    protected function matches(mixed $value, string $expected): bool
    {
        return $value === null || match ($expected) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'number', 'float' => is_int($value) || is_float($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
    }
}
