<?php

namespace Andmarruda\LaravelAgents\Observability;

use Andmarruda\LaravelAgents\Observability\Contracts\TraceExporter;
use Andmarruda\LaravelAgents\Observability\Contracts\TraceStore;
use Andmarruda\LaravelAgents\Observability\Data\Span;
use Andmarruda\LaravelAgents\Observability\Data\TokenUsage;
use Andmarruda\LaravelAgents\Observability\Data\Trace;
use Andmarruda\LaravelAgents\Observability\Events\TraceFinished;
use Andmarruda\LaravelAgents\Observability\Support\CostCalculator;
use Andmarruda\LaravelAgents\Observability\Support\LaravelEventDispatcher;
use DateTimeImmutable;
use Throwable;

class TraceManager
{
    /**
     * @var array<int, Trace>
     */
    protected array $traceStack = [];

    /**
     * @var array<int, Span>
     */
    protected array $spanStack = [];

    public function __construct(
        protected TraceStore $store,
        protected TraceExporter $exporter,
        protected CostCalculator $costs,
        protected LaravelEventDispatcher $events = new LaravelEventDispatcher(),
        protected bool $enabled = true,
        protected array $redactKeys = [],
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function startTrace(string $name, array $attributes = []): ?Trace
    {
        if (! $this->enabled) {
            return null;
        }

        $trace = new Trace($this->newId(), $name, new DateTimeImmutable(), $this->redact($attributes));
        $this->traceStack[] = $trace;

        return $trace;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function finishTrace(?Trace $trace, array $attributes = []): void
    {
        if (! $trace) {
            return;
        }

        $trace->finish($this->redact($attributes));
        $this->removeTrace($trace);
        $this->store->put($trace);
        $this->exporter->export($trace);
        $this->events->dispatch(new TraceFinished($trace));
    }

    public function failTrace(?Trace $trace, Throwable $throwable): void
    {
        if (! $trace) {
            return;
        }

        $trace->fail($throwable);
        $this->removeTrace($trace);
        $this->store->put($trace);
        $this->exporter->export($trace);
        $this->events->dispatch(new TraceFinished($trace));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, string $kind, array $attributes = []): ?Span
    {
        if (! $this->enabled || ! $this->currentTrace()) {
            return null;
        }

        $trace = $this->currentTrace();
        $span = new Span(
            id: $this->newId(),
            traceId: $trace->id,
            parentId: $this->currentSpan()?->id,
            name: $name,
            kind: $kind,
            startedAt: new DateTimeImmutable(),
            attributes: $this->redact($attributes),
        );

        $trace->addSpan($span);
        $this->spanStack[] = $span;

        return $span;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function finishSpan(?Span $span, array $attributes = []): void
    {
        if (! $span) {
            return;
        }

        $span->finish($this->redact($attributes));
        $this->removeSpan($span);
    }

    public function failSpan(?Span $span, Throwable $throwable): void
    {
        if (! $span) {
            return;
        }

        $span->fail($throwable);
        $this->removeSpan($span);
    }

    public function currentTrace(): ?Trace
    {
        return $this->traceStack[array_key_last($this->traceStack)] ?? null;
    }

    public function currentSpan(): ?Span
    {
        return $this->spanStack[array_key_last($this->spanStack)] ?? null;
    }

    public function dispatch(object $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->events->dispatch($event);
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    public function modelMetadata(string $provider, string $model, array $usage): array
    {
        $tokens = TokenUsage::fromArray($usage);
        $cost = $this->costs->forModel($provider, $model, $tokens);

        return array_filter([
            'model.provider' => $provider,
            'model.name' => $model,
            'model.usage' => $tokens->toArray(),
            'model.cost' => $cost?->toArray(),
        ], fn (mixed $value) => $value !== null);
    }

    protected function removeTrace(Trace $trace): void
    {
        $this->traceStack = array_values(array_filter(
            $this->traceStack,
            fn (Trace $stacked) => $stacked !== $trace,
        ));
    }

    protected function removeSpan(Span $span): void
    {
        $this->spanStack = array_values(array_filter(
            $this->spanStack,
            fn (Span $stacked) => $stacked !== $span,
        ));
    }

    protected function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), array_map('strtolower', $this->redactKeys), true)) {
            return '[REDACTED]';
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $item) {
            $redacted[$itemKey] = $this->redact($item, is_string($itemKey) ? $itemKey : null);
        }

        return $redacted;
    }
}
