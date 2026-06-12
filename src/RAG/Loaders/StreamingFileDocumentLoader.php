<?php

namespace Andmarruda\LaravelAgents\RAG\Loaders;

use Andmarruda\LaravelAgents\RAG\Contracts\StreamingDocumentLoader;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use InvalidArgumentException;
use RuntimeException;

class StreamingFileDocumentLoader implements StreamingDocumentLoader
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        protected string $path,
        protected array $metadata = [],
        protected ?string $mimeType = null,
        protected int $segmentBytes = 1_048_576,
        protected int $maxBytes = 104_857_600,
    ) {
        if ($segmentBytes < 1 || $maxBytes < $segmentBytes) {
            throw new InvalidArgumentException('Streaming document limits are invalid.');
        }
    }

    public function load(): array
    {
        return iterator_to_array($this->documents(), false);
    }

    public function documents(): iterable
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("Document file [{$this->path}] is not readable.");
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Document file [{$this->path}] could not be opened.");
        }

        $buffer = '';
        $bytes = 0;
        $index = 0;
        $source = realpath($this->path) ?: $this->path;
        $mime = $this->mimeType
            ?? (function_exists('mime_content_type') ? mime_content_type($this->path) : null)
            ?: 'text/plain';

        try {
            while (($line = fgets($handle, $this->segmentBytes + 1)) !== false) {
                $bytes += strlen($line);
                if ($bytes > $this->maxBytes) {
                    throw new RuntimeException("Document file [{$this->path}] exceeds the {$this->maxBytes} byte streaming limit.");
                }

                if ($buffer !== '' && strlen($buffer) + strlen($line) > $this->segmentBytes) {
                    yield $this->document($buffer, $source, $mime, $index++);
                    $buffer = '';
                }

                $buffer .= $line;
            }

            if ($buffer !== '') {
                yield $this->document($buffer, $source, $mime, $index);
            }
        } finally {
            fclose($handle);
        }
    }

    protected function document(string $content, string $source, string $mime, int $index): Document
    {
        if (str_contains($content, "\0") || (function_exists('mb_check_encoding') && ! mb_check_encoding($content, 'UTF-8'))) {
            throw new RuntimeException("Document file [{$this->path}] contains unsupported binary or malformed UTF-8 content.");
        }

        return Document::fromText($content, [
            'filename' => basename($this->path),
            'stream_segment' => $index,
            ...$this->metadata,
        ], $source.'#segment='.$index, $mime);
    }
}
