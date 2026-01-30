<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Enums\TaskType;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Str;

class DocumentService
{
    protected Parser $parser;

    /**
     * Canonical TOC → Category map
     */
    private const CATEGORY_MAP = [

        // INTRODUCTION
        'INTRODUCTION' => 'Introduction',
        'THE ORGANIZATION' => 'Introduction',

        // GENERAL EMPLOYMENT
        'GENERAL EMPLOYMENT INFORMATION' => 'Employment',
        'EMPLOYMENT AT-WILL' => 'Employment',
        'EQUAL EMPLOYMENT OPPORTUNITY' => 'Employment',
        'HARASSMENT' => 'Conduct',
        'ACCOMMODATIONS' => 'Employment',
        'OPEN DOOR POLICY' => 'Employment',

        // DOCUMENTATION
        'EMPLOYMENT DOCUMENTATION AND STATUS' => 'Employment',
        'EMPLOYMENT DOCUMENTATION' => 'Employment',
        'PERSONNEL DATA CHANGES' => 'Employment',
        'ACCESS TO PERSONNEL FILES' => 'Legal',
        'EMPLOYEE REFERENCE REQUESTS' => 'Legal',

        // PAYROLL
        'PAYROLL, SCHEDULING AND OVERTIME PRACTICES' => 'Payroll',
        'TIMEKEEPING' => 'Payroll',
        'PAY CORRECTIONS' => 'Payroll',
        'MEALS AND REST BREAKS' => 'Payroll',
        'OVERTIME' => 'Payroll',

        // BENEFITS & LEAVE
        'BENEFITS AND LEAVES OF ABSENCE' => 'Benefits',
        'EMPLOYEE BENEFITS' => 'Benefits',
        'VACATION BENEFITS' => 'Leave',
        'HOLIDAYS' => 'Leave',
        'SICK LEAVE BENEFITS' => 'Leave',
        'WORKERS’ COMPENSATION INSURANCE' => 'Benefits',
        'HEALTH INSURANCE' => 'Benefits',
        'FAMILY CARE, MEDICAL, AND MILITARY FAMILY LEAVE' => 'Leave',
        'PREGNANCY LEAVE' => 'Leave',
        'OTHER DISABILITY LEAVES' => 'Leave',
        'MILITARY LEAVE' => 'Leave',
        'BEREAVEMENT LEAVE' => 'Leave',
        'TIME OFF TO VOTE' => 'Leave',
        'TIME OFF FOR SCHOOL ACTIVITIES' => 'Leave',

        // CONDUCT
        'STANDARDS OF CONDUCT' => 'Conduct',
        'WORKPLACE VIOLENCE PREVENTION' => 'Safety',
        'DRUG AND ALCOHOL USE' => 'Conduct',
        'SMOKING' => 'Conduct',
        'ATTENDANCE AND PUNCTUALITY' => 'Conduct',
        'DRESS AND GROOMING STANDARDS' => 'Conduct',
        'NON-DISCLOSURE' => 'Legal',
        'USE OF PHONE AND MAIL SYSTEMS' => 'IT_Policy',
        'INTERNET, E-MAIL, AND ELECTRONIC COMMUNICATIONS' => 'IT_Policy',
        'WORKPLACE MONITORING' => 'IT_Policy',
        'ETHICS AND CONDUCT' => 'Conduct',
        'SAFETY' => 'Safety',
        'VISITORS IN THE WORKPLACE' => 'Safety',

        // ACK
        'ACKNOWLEDGEMENT FORM' => 'Acknowledgement',
    ];

    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Entry point
     */
    public function ingestPdf(string $filePath, string $title): Document
    {
        $pdf = $this->parser->parseFile($filePath);
        $rawText = $pdf->getText();

        $text = $this->normalizeText($rawText);
        $text = $this->addStructuralMarkers($text);

        $document = Document::create([
            'filename' => basename($filePath),
            'title'    => $title,
        ]);

        $chunks = $this->chunkByStructure($text);

        foreach ($chunks as $chunk) {
            $this->storeChunk($document, $chunk);
        }

        return $document;
    }

    /**
     * Normalize PDF noise
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["’", "“", "”"], ["'", '"', '"'], $text);

        $text = preg_replace('/^\s*\d+\s*$/m', '', $text);
        $text = preg_replace('/\[(ORGANIZATION|TITLE|DATE|INSERT.*?|NAME)\]/i', '', $text);

        $text = preg_replace('/PUBLIC COUNSEL.*?\n/i', '', $text);
        $text = preg_replace('/COMMUNITY DEVELOPMENT PROJECT.*?\n/i', '', $text);
        $text = preg_replace('/AUGUST\s+\d{4}/i', '', $text);

        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Insert SECTION / SUBSECTION markers
     */
    private function addStructuralMarkers(string $text): string
    {
        // Roman numeral sections
        $text = preg_replace(
            "/\n([IVXLCDM]+\.\s+[A-Z][A-Z\s,&\-]+)\n/",
            "\n\nSECTION: $1\n",
            $text
        );

        // Lettered subsections
        $text = preg_replace(
            "/\n([A-Z])\.\s+([A-Za-z].+)\n/",
            "\nSUBSECTION: $2\n",
            $text
        );

        return $text;
    }

    /**
     * Core chunking logic
     */
    private function chunkByStructure(string $text): array
    {
        $lines = explode("\n", $text);

        $chunks = [];
        $section = 'General';
        $subsection = null;
        $buffer = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (Str::startsWith($line, 'SECTION:')) {
                $this->flushChunk($chunks, $section, $subsection, $buffer);

                $section = trim(Str::after($line, 'SECTION:'));
                $subsection = null;
                $buffer = [];
                continue;
            }

            if (Str::startsWith($line, 'SUBSECTION:')) {
                $this->flushChunk($chunks, $section, $subsection, $buffer);

                $subsection = trim(Str::after($line, 'SUBSECTION:'));
                $buffer = [];
                continue;
            }

            $buffer[] = $line;
        }

        $this->flushChunk($chunks, $section, $subsection, $buffer);

        return $chunks;
    }

    /**
     * Persist a completed chunk
     */
    private function flushChunk(array &$chunks, string $section, ?string $subsection, array &$buffer): void
    {
        if (empty($buffer)) return;

        $content = trim(implode("\n", $buffer));
        if (Str::wordCount($content) < 40) return;

        $category = $this->resolveCategory($section, $subsection);

        $chunks[] = [
            'content' => $content,
            'metadata' => [
                'section'    => $section,
                'subsection' => $subsection,
                'category'   => $category,
            ],
        ];

        $buffer = [];
    }

    /**
     * Deterministic category resolution
     */
    private function resolveCategory(string $section, ?string $subsection): string
    {
        $haystack = Str::upper($section . ' ' . ($subsection ?? ''));

        foreach (self::CATEGORY_MAP as $needle => $category) {
            if (Str::contains($haystack, $needle)) {
                return $category;
            }
        }

        return 'General';
    }

    /**
     * Embed & store chunk
     */
    private function storeChunk(Document $document, array $chunk): void
    {
        try {
            $embedding = Gemini::embeddingModel('gemini-embedding-001')
                ->embedContent(
                    $chunk['content'],
                    taskType: TaskType::RETRIEVAL_DOCUMENT,
                    outputDimensionality: 768
                );

            DocumentChunk::create([
                'document_id' => $document->id,
                'content'     => $chunk['content'],
                'metadata'    => $chunk['metadata'],
                'embedding'   => $embedding->embedding->values,
            ]);

        } catch (\Throwable $e) {
            logger()->error(
                'Embedding failed',
                ['error' => $e->getMessage(), 'metadata' => $chunk['metadata']]
            );
        }
    }
}
