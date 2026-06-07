<?php

namespace Andmarruda\LaravelAgents\MCP\Auth;

class McpAuthenticationResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $message = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(?string $message = null): self
    {
        return new self(false, $message);
    }
}
