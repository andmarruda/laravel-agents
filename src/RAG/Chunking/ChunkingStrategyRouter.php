<?php

namespace Andmarruda\LaravelAgents\RAG\Chunking;

use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ChunkingStrategyRouter implements Chunker
{
    /**
     * @param array<string, Chunker|class-string<Chunker>> $strategies
     * @param array<string, string> $extensions
     * @param array<string, string> $mimeTypes
     */
    public function __construct(
        protected array $strategies,
        protected string $default = 'semantic',
        protected array $extensions = [],
        protected array $mimeTypes = [],
        protected ?Container $container = null,
    ) {
    }

    public function chunk(Document $document): array
    {
        $name = is_string($document->metadata['chunking_strategy'] ?? null)
            ? $document->metadata['chunking_strategy']
            : $this->detect($document);
        $chunker = $this->resolve($name);
        $chunks = $chunker->chunk($document);

        return array_map(fn ($chunk) => new \Andmarruda\LaravelAgents\RAG\Data\DocumentChunk(
            $chunk->id,
            $chunk->documentId,
            $chunk->content,
            $chunk->index,
            [...$chunk->metadata, 'chunking_strategy' => $name, 'chunking_version' => '1'],
        ), $chunks);
    }

    protected function detect(Document $document): string
    {
        $mime = strtolower($document->mimeType);
        if (isset($this->mimeTypes[$mime])) {
            return $this->mimeTypes[$mime];
        }

        $path = $document->metadata['filename'] ?? $document->source;
        $extension = is_string($path) ? strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)) : '';

        return $this->extensions[$extension] ?? $this->default;
    }

    protected function resolve(string $name): Chunker
    {
        $strategy = $this->strategies[$name] ?? null;
        if ($strategy instanceof Chunker) {
            return $strategy;
        }

        if (is_string($strategy)) {
            $instance = $this->container?->make($strategy) ?? new $strategy();
            if ($instance instanceof Chunker) {
                return $instance;
            }
        }

        throw new InvalidArgumentException("Unsupported RAG chunking strategy [{$name}].");
    }
}
