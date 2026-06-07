<?php

namespace Andmarruda\LaravelAgents\MCP\Client\Transports;

use RuntimeException;

class StdioMcpTransport implements McpTransport
{
    /**
     * @var resource|null
     */
    protected mixed $process = null;

    /**
     * @var array<int, resource>
     */
    protected array $pipes = [];

    /**
     * @param array<int, string> $command
     * @param array<string, string> $environment
     */
    public function __construct(
        protected array $command,
        protected ?string $cwd = null,
        protected array $environment = [],
        protected int $timeout = 30,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function request(string $method, array $params = []): array
    {
        $this->ensureProcess();

        $id = uniqid('mcp_', true);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Unable to encode MCP stdio request body.');
        }

        fwrite($this->pipes[0], $payload."\n");

        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $remaining = max(0, (int) ceil($deadline - microtime(true)));
            $ready = @stream_select($read, $write, $except, $remaining, 0);

            if ($ready === false) {
                throw new RuntimeException('Unable to read from MCP stdio server.');
            }

            if ($ready === 0) {
                continue;
            }

            $line = fgets($this->pipes[1]);

            if ($line === false) {
                continue;
            }

            $decoded = json_decode(trim($line), true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['id'] ?? null) !== $id) {
                continue;
            }

            if (isset($decoded['error'])) {
                $message = is_array($decoded['error'])
                    ? (string) ($decoded['error']['message'] ?? 'MCP stdio server error.')
                    : 'MCP stdio server error.';

                throw new RuntimeException($message);
            }

            return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        }

        throw new RuntimeException('Timed out waiting for MCP stdio server response.');
    }

    protected function ensureProcess(): void
    {
        if (is_resource($this->process)) {
            return;
        }

        if ($this->command === []) {
            throw new RuntimeException('MCP stdio command cannot be empty.');
        }

        $this->process = proc_open(
            $this->command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            $this->cwd,
            $this->environment === [] ? null : $this->environment,
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException('Unable to start MCP stdio server.');
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }
}
