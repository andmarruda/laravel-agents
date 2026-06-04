<?php

namespace Andmarruda\LaravelAgents\Memory;

use Andmarruda\LaravelAgents\Contracts\Memory\LongTermMemory;
use Illuminate\Database\ConnectionInterface;

class DatabaseLongTermAdapter implements LongTermMemory
{
    public function __construct(
        protected ConnectionInterface $db,
        protected string $table = 'agent_memories',
    ) {
    }

    public function store(string $agent, string $scope, string $key, mixed $value): void
    {
        $this->db->table($this->table)->updateOrInsert(
            ['agent' => $agent, 'scope' => $scope, 'key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );
    }

    public function retrieve(string $agent, string $scope, ?string $key = null): array
    {
        $query = $this->db->table($this->table)
            ->where('agent', $agent)
            ->where('scope', $scope);

        if ($key !== null) {
            $row = $query->where('key', $key)->first();

            if (! $row) {
                return [];
            }

            return [$row->key => json_decode($row->value, true)];
        }

        return $query->get()->mapWithKeys(
            fn ($row) => [$row->key => json_decode($row->value, true)],
        )->all();
    }

    public function forget(string $agent, string $scope, ?string $key = null): void
    {
        $query = $this->db->table($this->table)
            ->where('agent', $agent)
            ->where('scope', $scope);

        if ($key !== null) {
            $query->where('key', $key);
        }

        $query->delete();
    }
}
