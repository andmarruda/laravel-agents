<?php

namespace Andmarruda\LaravelAgents\Observability\Support;

use Andmarruda\LaravelAgents\Observability\Data\Cost;
use Andmarruda\LaravelAgents\Observability\Data\TokenUsage;

class CostCalculator
{
    /**
     * @param array<string, array{input_per_1k?: float|int, output_per_1k?: float|int, currency?: string}> $pricing
     */
    public function __construct(
        protected array $pricing = [],
    ) {
    }

    public function forModel(string $provider, string $model, TokenUsage $usage): ?Cost
    {
        $key = $provider.'/'.$model;
        $price = $this->pricing[$key] ?? $this->pricing[$model] ?? null;

        if (! is_array($price)) {
            return null;
        }

        $inputRate = (float) ($price['input_per_1k'] ?? 0);
        $outputRate = (float) ($price['output_per_1k'] ?? 0);
        $amount = ($usage->inputTokens / 1000 * $inputRate) + ($usage->outputTokens / 1000 * $outputRate);

        return new Cost(round($amount, 8), (string) ($price['currency'] ?? 'USD'));
    }
}
