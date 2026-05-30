<?php

namespace Andmarruda\LaravelAgents\Adapters\Models\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait BuildsHttpClient
{
    /**
     * @param array<string, mixed> $runtime
     */
    protected function client(array $runtime = []): PendingRequest
    {
        $timeout = $runtime['timeout'] ?? $this->runtime['timeout'] ?? 60;
        $retryTimes = $runtime['retry_times'] ?? $this->runtime['retry_times'] ?? 0;
        $retrySleep = $runtime['retry_sleep'] ?? $this->runtime['retry_sleep'] ?? 250;

        return Http::timeout($timeout)->retry($retryTimes, $retrySleep);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function payloadOptions(array $options): array
    {
        unset($options['timeout'], $options['retry_times'], $options['retry_sleep']);

        return $options;
    }
}
