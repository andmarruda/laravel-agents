<?php

namespace Andmarruda\LaravelAgents\MCP\Server;

class HttpMcpController
{
    public function __construct(
        protected McpRequestHandler $handler,
    ) {
    }

    public function __invoke(mixed $request): mixed
    {
        $payload = [];

        if (is_object($request) && method_exists($request, 'all')) {
            $payload = $request->all();
        } elseif (is_array($request)) {
            $payload = $request;
        }

        $response = $this->handler->handle(is_array($payload) ? $payload : [], $request);

        if ($response === null) {
            return function_exists('response') ? response('', 202) : null;
        }

        if (function_exists('response')) {
            return response()->json($response);
        }

        return $response;
    }
}
