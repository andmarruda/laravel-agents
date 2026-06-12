<?php

namespace Andmarruda\LaravelAgents\RAG\Chunking;

use Andmarruda\LaravelAgents\RAG\Contracts\Chunker;
use Andmarruda\LaravelAgents\RAG\Data\Document;
use Andmarruda\LaravelAgents\RAG\Data\DocumentChunk;
use InvalidArgumentException;

class CodeChunker implements Chunker
{
    public function __construct(
        protected int $chunkSize = 2000,
        protected int $overlap = 100,
    ) {
        if ($chunkSize < 1 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Code chunk size and overlap are invalid.');
        }
    }

    public function chunk(Document $document): array
    {
        $parts = preg_split(
            '/(?=^(?:(?:export|final|abstract|async|public|protected|private|static)\s+)*(?:class|interface|trait|enum|function|def|func|fn)\s+)/m',
            trim($document->content),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $fallback = new RecursiveCharacterChunker($this->chunkSize, $this->overlap, ["\n\n", "\n", ' ', '']);
        $contents = [];

        if (count($parts) > 1 && strlen(trim($parts[0])) < 80) {
            $parts[1] = rtrim($parts[0])."\n".ltrim($parts[1]);
            array_shift($parts);
        }

        foreach ($parts as $part) {
            if (strlen($part) <= $this->chunkSize) {
                $contents[] = trim($part);
                continue;
            }

            foreach ($fallback->chunk(Document::fromText($part, source: $document->source, mimeType: $document->mimeType)) as $chunk) {
                $contents[] = $chunk->content;
            }
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
                'chunking_strategy' => 'code',
                'chunking_version' => '1',
            ],
        ), $contents, array_keys($contents));
    }
}
