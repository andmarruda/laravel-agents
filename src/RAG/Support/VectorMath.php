<?php

namespace Andmarruda\LaravelAgents\RAG\Support;

use InvalidArgumentException;

class VectorMath
{
    /**
     * @param array<int, float|int> $a
     * @param array<int, float|int> $b
     */
    public static function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            throw new InvalidArgumentException('Vectors must be non-empty and have matching dimensions.');
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $left = (float) $value;
            $right = (float) $b[$index];
            $dot += $left * $right;
            $normA += $left * $left;
            $normB += $right * $right;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
