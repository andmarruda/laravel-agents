<?php

namespace Andmarruda\LaravelAgents\RAG\Chunking;

use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\DocumentChunk;
use InvalidArgumentException;

class RecursiveCharacterChunker implements Chunker
{
    /**
     * @param array<int, string> $separators
     */
    public function __construct(
        protected int $chunkSize = 1000,
        protected int $overlap = 150,
        protected array $separators = ["\n\n", "\n", '. ', ' ', ''],
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        if ($overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Chunk overlap must be non-negative and smaller than chunk size.');
        }
    }

    public function chunk(Document $document): array
    {
        $parts = $this->split(trim($document->content), 0);
        $chunks = [];
        $buffer = '';

        foreach ($parts as $part) {
            $candidate = trim($buffer === '' ? $part : $buffer.' '.$part);

            if (strlen($candidate) <= $this->chunkSize) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            $prefix = $this->overlap > 0 && $buffer !== '' ? substr($buffer, -$this->overlap) : '';
            $buffer = trim($prefix.' '.$part);

            while (strlen($buffer) > $this->chunkSize) {
                $chunks[] = substr($buffer, 0, $this->chunkSize);
                $buffer = substr($buffer, $this->chunkSize - $this->overlap);
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_map(fn (string $content, int $index) => new DocumentChunk(
            id: hash('sha256', $document->id."\0".$index."\0".$content),
            documentId: $document->id,
            content: $content,
            index: $index,
            metadata: [
                ...$document->metadata,
                'document_id' => $document->id,
                'chunk_index' => $index,
                'source' => $document->source,
                'mime_type' => $document->mimeType,
                'checksum' => $document->checksum,
            ],
        ), $chunks, array_keys($chunks));
    }

    /**
     * @return array<int, string>
     */
    protected function split(string $text, int $separatorIndex): array
    {
        if ($text === '' || strlen($text) <= $this->chunkSize) {
            return $text === '' ? [] : [$text];
        }

        $separator = $this->separators[$separatorIndex] ?? '';

        if ($separator === '') {
            return str_split($text, $this->chunkSize);
        }

        $parts = array_values(array_filter(array_map('trim', explode($separator, $text)), fn (string $part) => $part !== ''));
        $result = [];

        foreach ($parts as $part) {
            array_push($result, ...$this->split($part, $separatorIndex + 1));
        }

        return $result;
    }
}
