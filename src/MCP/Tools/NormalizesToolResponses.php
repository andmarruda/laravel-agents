<?php

namespace Andmarruda\LaravelAgents\MCP\Tools;

trait NormalizesToolResponses
{
    protected function normalizeResponse(mixed $response): mixed
    {
        if (is_object($response) && method_exists($response, 'getData')) {
            return json_decode(json_encode($response->getData(true), JSON_UNESCAPED_SLASHES) ?: 'null', true);
        }

        if (is_object($response) && method_exists($response, 'resolve')) {
            return $response->resolve();
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            return $response->toArray();
        }

        if (is_object($response) && method_exists($response, 'getContent')) {
            $content = $response->getContent();
            $decoded = is_string($content) ? json_decode($content, true) : null;

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
        }

        return $response;
    }
}
