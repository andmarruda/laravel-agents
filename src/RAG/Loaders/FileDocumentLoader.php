<?php

namespace Andmarruda\LaravelAgents\RAG\Loaders;

use Andmarruda\LaravelAgents\RAG\Contracts\DocumentLoader;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use InvalidArgumentException;
use RuntimeException;

class FileDocumentLoader implements DocumentLoader
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        protected string $path,
        protected array $metadata = [],
        protected ?string $mimeType = null,
        protected int $maxBytes = 10_485_760,
    ) {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Document file byte limit must be at least 1.');
        }
    }

    public function load(): array
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("Document file [{$this->path}] is not readable.");
        }

        $size = filesize($this->path);

        if ($size !== false && $size > $this->maxBytes) {
            throw new RuntimeException("Document file [{$this->path}] exceeds the {$this->maxBytes} byte limit.");
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Document file [{$this->path}] could not be read.");
        }

        $mimeType = $this->mimeType
            ?? (function_exists('mime_content_type') ? mime_content_type($this->path) : null)
            ?: 'text/plain';

        return [Document::fromText($content, [
            'filename' => basename($this->path),
            ...$this->metadata,
        ], realpath($this->path) ?: $this->path, $mimeType)];
    }
}
