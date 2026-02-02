<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Messages\DeveloperMessage;
use App\Models\DocumentChunk;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Enums\TaskType;

class PolicyAssistant extends Agent
{
    protected $provider = 'gemini';
    protected $model = 'gemini-2.5-flash-lite';
    protected $history = 'session';
    protected $temperature = 0.3;
    protected $maxCompletionTokens = 1500;
    protected $sessionKey = 'chat_history';

    public function __construct()
    {
        parent::__construct($this->provider);

        if (auth()->check()) {
            $this->sessionKey = 'chat_history_' . auth()->id();
        }
    }

    /**
     * Instructions for the AI, now including category prioritization.
     */
    public function instructions(): string
    {
        return <<<TEXT
You are the "Company Compliance & Decision Engine." Your role is to analyze policy snippets and provide clear, actionable guidance.

### MANDATORY PROTOCOL:
1. **Search Phase**: Always use the 'searchPolicies' tool first.
2. **Category Awareness**: You may prioritize or filter results by category (e.g., Leave, Travel, Expense).
3. **Conflict Check**: If multiple policy snippets conflict, evaluate versions or dates. Flag conflicts to the user if unclear.
4. **Decision Support**: Provide actionable guidance, not just quotes:
   - What the rule is.
   - If the user is in violation or late.
   - Exact "Next Step" (forms, approvals, emails).

### RESPONSE FORMAT:
- **Policy Reference**: Which document you are quoting
- **Category**: Policy category
- **Status/Assessment**: e.g., "You are currently 5 days past the deadline"
- **⚠️ Conflict Alert**: Only if policies disagree
- **Action Plan**: Numbered steps
TEXT;
    }

    /**
     * Prompt user query, detect category automatically, and inject relevant context.
     */
    public function prompt(string $message)
    {
        // 1. Detect category automatically
        $category = $this->detectCategory($message);

        // 2. Get relevant policies (filtered by category if detected)
        $documents = $this->getRelevantPolicies($message, $category);

        if (!empty($documents)) {
            $context = "### Context\nUse these policy snippets to answer the user:\n\n" . $documents;
            $this->chatHistory()->addMessage(new DeveloperMessage($context));
        }

        return $message;
    }

    /**
     * Automatic category detection from user query using keyword mapping.
     */
    private function detectCategory(string $message): ?string
    {
        $keywords = [
            'Leave' => ['leave', 'vacation', 'sick', 'pto', 'time off'],
            'Travel' => ['travel', 'trip', 'journey', 'per diem'],
            'Expense' => ['expense', 'invoice', 'budget'],
            'Conduct' => ['conduct', 'ethics', 'behavior'],
        ];

        $messageLower = strtolower($message);

        foreach ($keywords as $category => $terms) {
            foreach ($terms as $term) {
                if (str_contains($messageLower, $term)) {
                    return $category;
                }
            }
        }

        return null; // No specific category detected
    }

    /**
     * Retrieve relevant policy chunks using hybrid search with semantic + keyword + metadata.
     */
    private function getRelevantPolicies(string $query, ?string $category = null): string
    {
        // 1. Compute embedding
        $cacheKey = 'embed_' . md5($query);

        $embeddingValues = cache()->remember($cacheKey, now()->addDay(), function() use ($query) {
            $response = Gemini::embeddingModel('gemini-embedding-001')
                ->embedContent($query, taskType: TaskType::RETRIEVAL_QUERY, outputDimensionality: 768);
            return $response->embedding->values;
        });

        $vectorString = '[' . implode(',', $embeddingValues) . ']';
        $threshold = 0.45;

        // 2. Hybrid Search with optional category filtering
        $chunks = DocumentChunk::with('document')
            ->hybridSearch($vectorString, $query, $threshold, 0.7, 0.3, $category)
            ->limit(3)
            ->get();

        if ($chunks->isEmpty()) {
            return "NOTICE: No relevant policy sections were found for this inquiry.";
        }

        // 3. Map results with category & metadata display
        return $chunks->map(function($c) {
            $title = $c->document ? $c->document->title : 'Unknown Document';
            $category = $c->metadata['category'] ?? 'General';
            $section = $c->metadata['section'] ?? '';
            $subsection = $c->metadata['subsection'] ?? '';

            $semantic = round(1 - $c->distance, 2);
            $keyword = round($c->search_rank ?? 0, 2);
            $hybrid = round($c->hybrid_score ?? 0, 2);

            return "[Source: {$title}] (Category: {$category} | Section: {$section} | Subsection: {$subsection})\n" .
                "Scores → Semantic: {$semantic}, Keyword: {$keyword}, Hybrid: {$hybrid}\n" .
                $c->content;
        })->implode("\n\n---\n\n");
    }
}
