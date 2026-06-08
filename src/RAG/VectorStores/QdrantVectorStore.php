<?php

namespace Andmarruda\LaravelAgents\RAG\VectorStores;

use Andmarruda\LaravelAgents\RAG\Contracts\VectorStore;
use Andmarruda\LaravelAgents\RAG\Data\SearchResult;
use Illuminate\Http\Client\Factory;

class QdrantVectorStore implements VectorStore
{
    public function __construct(
        protected string $baseUrl,
        protected string $collection,
        protected ?string $apiKey = null,
        protected ?Factory $http = null,
        protected int $timeout = 60,
        protected bool $autoCreateCollection = true,
    ) {
    }

    public function upsert(array $records, ?string $namespace = null): void
    {
        if ($records === []) {
            return;
        }

        if ($this->autoCreateCollection) {
            $this->ensureCollection(count($records[0]->vector));
        }

        $this->writePoints($records, $namespace);
    }

    public function replaceDocument(string $documentId, array $records, ?string $namespace = null): void
    {
        if ($records !== [] && $this->autoCreateCollection) {
            $this->ensureCollection(count($records[0]->vector));
        }

        $this->deleteByDocument($documentId, $namespace);
        $this->writePoints($records, $namespace);
    }

    /**
     * @param array<int, \Andmarruda\LaravelAgents\RAG\Data\VectorRecord> $records
     */
    protected function writePoints(array $records, ?string $namespace): void
    {
        if ($records === []) {
            return;
        }

        $points = array_map(fn ($record) => [
            'id' => $this->pointId($record->id),
            'vector' => array_map('floatval', $record->vector),
            'payload' => [
                'record_id' => $record->id,
                'document_id' => $record->documentId,
                'content' => $record->content,
                'namespace' => $namespace ?? 'default',
                'metadata' => $record->metadata,
            ],
        ], $records);

        $this->request()->put($this->url('/points?wait=true'), ['points' => $points])->throw();
    }

    public function search(array $vector, int $limit = 5, array $filters = [], ?string $namespace = null): array
    {
        $must = [[
            'key' => 'namespace',
            'match' => ['value' => $namespace ?? 'default'],
        ]];

        foreach ($filters as $key => $value) {
            $must[] = [
                'key' => 'metadata.'.$key,
                'match' => ['value' => $value],
            ];
        }

        $response = $this->request()->post($this->url('/points/search'), [
            'vector' => array_map('floatval', $vector),
            'limit' => max(0, $limit),
            'with_payload' => true,
            'filter' => ['must' => $must],
        ])->throw()->json();

        return array_map(fn (array $row) => new SearchResult(
            id: (string) ($row['payload']['record_id'] ?? $row['id']),
            content: (string) ($row['payload']['content'] ?? ''),
            score: (float) ($row['score'] ?? 0),
            metadata: is_array($row['payload']['metadata'] ?? null) ? $row['payload']['metadata'] : [],
            documentId: isset($row['payload']['document_id']) ? (string) $row['payload']['document_id'] : null,
        ), $response['result'] ?? []);
    }

    public function deleteByDocument(string $documentId, ?string $namespace = null): void
    {
        $response = $this->request()->post($this->url('/points/delete?wait=true'), [
            'filter' => [
                'must' => [
                    ['key' => 'document_id', 'match' => ['value' => $documentId]],
                    ['key' => 'namespace', 'match' => ['value' => $namespace ?? 'default']],
                ],
            ],
        ]);

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        $request = ($this->http ?? app(Factory::class))->timeout($this->timeout);

        return $this->apiKey ? $request->withHeader('api-key', $this->apiKey) : $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/collections/'.rawurlencode($this->collection).$path;
    }

    protected function ensureCollection(int $dimensions): void
    {
        $response = $this->request()->get($this->url(''));

        if ($response->successful()) {
            return;
        }

        if ($response->status() !== 404) {
            $response->throw();
        }

        $response = $this->request()->put($this->url(''), [
            'vectors' => [
                'size' => $dimensions,
                'distance' => 'Cosine',
            ],
        ]);

        if ($response->status() !== 409) {
            $response->throw();
        }
    }

    protected function pointId(string $id): string
    {
        $hash = substr(hash('sha256', $id), 0, 32);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
