<?php

namespace Andmarruda\LaravelAgents\Observability\Data;

class TokenUsage
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $totalTokens = 0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function fromArray(array $usage): self
    {
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        return new self($input, $output, $total, $usage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'raw' => $this->raw,
        ];
    }
}
