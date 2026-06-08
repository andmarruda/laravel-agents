<?php

namespace Andmarruda\LaravelAgents\Observability\Data;

class Cost
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency = 'USD',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
