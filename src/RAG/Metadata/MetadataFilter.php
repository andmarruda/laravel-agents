<?php

namespace Andmarruda\LaravelAgents\RAG\Metadata;

use Andmarruda\LaravelAgents\RAG\Data\MetadataNormalizer;
use InvalidArgumentException;

class MetadataFilter
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters, bool $allowNested = false): array
    {
        $filters = MetadataNormalizer::normalize($filters);

        foreach ($filters as $key => $value) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key)) {
                throw new InvalidArgumentException("RAG metadata filter key [{$key}] is invalid.");
            }

            if (! $allowNested && ! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("RAG metadata filter [{$key}] must be scalar or null.");
            }
        }

        return $filters;
    }
}
