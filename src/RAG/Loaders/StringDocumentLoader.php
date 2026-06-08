<?php

namespace Andmarruda\LaravelAgents\RAG\Loaders;

use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Data\Document;

class StringDocumentLoader implements DocumentLoader
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        protected string $content,
        protected array $metadata = [],
        protected ?string $source = null,
        protected string $mimeType = 'text/plain',
    ) {
    }

    public function load(): array
    {
        return [Document::fromText($this->content, $this->metadata, $this->source, $this->mimeType)];
    }
}
