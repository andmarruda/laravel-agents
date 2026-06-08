<?php

namespace Andmarruda\LaravelAgents\Observability;

use Andmarruda\LaravelAgents\Data\ModelResponse;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallFailed;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallFinished;
use Andmarruda\LaravelAgents\Observability\Events\ModelCallStarted;
use Andmarruda\LaravelAgents\Ports\ModelPort;
use Throwable;

class ObservableModelPort implements ModelPort
{
    public function __construct(
        protected ModelPort $inner,
        protected TraceManager $traces,
        protected ?string $requestedModel = null,
    ) {
    }

    public function capability(): string
    {
        return $this->inner->capability();
    }

    public function generate(array $messages, array $options = []): ModelResponse
    {
        if ($this->traces->currentSpan()?->name === 'model.generate') {
            return $this->inner->generate($messages, $options);
        }

        $rootTrace = $this->traces->currentTrace()
            ? null
            : $this->traces->startTrace('model.generate', [
                'model.requested' => $this->requestedModel,
                'model.message_count' => count($messages),
            ]);
        $span = $this->traces->startSpan('model.generate', 'model', [
            'model.requested' => $this->requestedModel,
            'model.message_count' => count($messages),
        ]);
        $this->traces->dispatch(new ModelCallStarted($this->requestedModel, $messages, $options));

        try {
            $response = $this->inner->generate($messages, $options);
            $metadata = $this->traces->modelMetadata($response->provider, $response->model, $response->usage);
            $this->traces->finishSpan($span, $metadata);
            $this->traces->dispatch(new ModelCallFinished($response));
            $this->traces->finishTrace($rootTrace, $metadata);

            return $response;
        } catch (Throwable $throwable) {
            $this->traces->failSpan($span, $throwable);
            $this->traces->dispatch(new ModelCallFailed($this->requestedModel, $throwable));
            $this->traces->failTrace($rootTrace, $throwable);

            throw $throwable;
        }
    }
}
