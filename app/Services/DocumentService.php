<?php
namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Gemini\Laravel\Facades\Gemini;
use Smalot\PdfParser\Parser;
use Gemini\Enums\TaskType;

class DocumentService
{
    protected $parser;

    public function __construct(Parser $parser) {
        $this->parser = $parser;
    }

    public function ingestPdf(string $filePath, string $title): Document
    {
        $pdf = $this->parser->parseFile($filePath);
        $rawText = $pdf->getText();

        // 1. CLEAN & NORMALIZE
        $cleanText = $this->normalizeHandbookText($rawText);

        $document = Document::create([
            'filename' => basename($filePath),
            'title' => $title,
        ]);

        // 2. CHUNK BY SECTION/HEADING
        $chunks = $this->chunkByPolicy($cleanText);

        foreach ($chunks as $content) {
            // Task type 'RETRIEVAL_DOCUMENT' is recommended for Gemini RAG
            $response = Gemini::embeddingModel('gemini-embedding-001')
                ->embedContent($content,taskType: TaskType::RETRIEVAL_DOCUMENT,
                    outputDimensionality: 768);



            DocumentChunk::create([
                'document_id' => $document->id,
                'content' => $content,
                'embedding' => $response->embedding->values,
            ]);
        }

        return $document;
    }

    private function normalizeHandbookText(string $text): string
    {
        // Remove Headers (specific to your document)
        $text = preg_replace('/PUBLIC COUNSEL.*AUGUST 2017/i', '', $text);

        // Remove Page Numbers (standalone digits on their own line)
        $text = preg_replace('/^\s*\d+\s*$/m', '', $text);

        // Normalize Whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text); // Max 2 newlines
        $text = preg_replace('/[ \t]+/', ' ', $text);    // Double spaces to single

        return trim($text);
    }

    private function chunkByPolicy(string $text): array
    {
        $chunks = [];
        $currentSection = "General";

        // Split text into lines to track headers
        $lines = explode("\n", $text);
        $currentChunk = "";

        foreach ($lines as $line) {
            // Identify Roman Numeral Sections (e.g., II. GENERAL EMPLOYMENT)
            if (preg_match('/^[IVX]+\.\s+([A-Z\s]+)/', $line, $matches)) {
                $currentSection = trim($matches[1]);
            }

            // If line is a Sub-policy (e.g., A. At-Will), start a new chunk
            if (preg_match('/^[A-Z]\.\s+/', $line)) {
                if (!empty($currentChunk)) $chunks[] = "[Section: $currentSection] " . trim($currentChunk);
                $currentChunk = $line . "\n";
            } else {
                $currentChunk .= $line . "\n";
            }
        }
        return $chunks;
    }
}
