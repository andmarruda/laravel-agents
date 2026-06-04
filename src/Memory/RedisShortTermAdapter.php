<?php

namespace Andmarruda\LaravelAgents\Memory;

use Andmarruda\LaravelAgents\Contracts\Memory\ShortTermMemory;
use Illuminate\Redis\Connections\Connection;

class RedisShortTermAdapter implements ShortTermMemory
{
    public function __construct(
        protected Connection $redis,
        protected string $prefix = 'agents:session:',
    ) {
    }

    public function append(string $sessionId, array $message, int $ttl): void
    {
        $key = $this->key($sessionId);

        $this->redis->rpush($key, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->redis->expire($key, $ttl);
    }

    public function messages(string $sessionId): array
    {
        $raw = $this->redis->lrange($this->key($sessionId), 0, -1);

        return array_map(
            fn (string $item) => json_decode($item, true),
            $raw,
        );
    }

    public function clear(string $sessionId): void
    {
        $this->redis->del($this->key($sessionId));
    }

    private function key(string $sessionId): string
    {
        return $this->prefix.$sessionId;
    }
}
