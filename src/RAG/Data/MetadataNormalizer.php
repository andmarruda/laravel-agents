<?php

namespace Andmarruda\LaravelAgents\RAG\Data;

use DateTimeInterface;
use JsonSerializable;

class MetadataNormalizer
{
    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function normalize(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $normalized[(string) $key] = self::value($value);
        }

        ksort($normalized);

        return $normalized;
    }

    protected static function value(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return self::value($value->jsonSerialize());
        }

        if (is_object($value)) {
            return self::value(get_object_vars($value));
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = self::value($item);
            }

            return $normalized;
        }

        return is_resource($value) ? (string) $value : $value;
    }
}
