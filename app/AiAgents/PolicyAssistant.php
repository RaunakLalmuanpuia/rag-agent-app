<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\DocumentChunk;
use Gemini\Laravel\Facades\Gemini;
use LarAgent\Messages\DeveloperMessage;
use Illuminate\Support\Facades\DB;
use Gemini\Enums\TaskType;

class PolicyAssistant extends Agent
{
    protected $provider = 'gemini';
    protected  $model = 'gemini-2.5-flash-lite'; // Note: Ensure you use a standard model string

    protected $history = 'cache';

    protected $temperature = 0.3;
    protected $maxCompletionTokens = 1500;
    protected $sessionKey = 'chat_history';


    public function __construct()
    {
        // This passes the provider name ('gemini') to the parent Agent class
        parent::__construct($this->provider);
    }

    public function instructions(): string
    {
        return <<<TEXT
    You are the "Company Compliance & Decision Engine." Your role is to analyze policy snippets and provide clear, actionable guidance.

    ### MANDATORY PROTOCOL:
    1. **Search Phase**: Always use the 'searchPolicies' tool first.
    2. **Conflict Check**: When you receive multiple policy snippets, evaluate if they contradict each other (e.g., the Expense Policy says 30 days, but the Travel Policy says 14 days).
    3. **Hierarchy of Truth**: If policies conflict, look for dates or versions. If no version is clear, flag the conflict to the user immediately.
    4. **Decision Support**: Do not just quote text. Tell the user:
       - What the rule is.
       - If they are in violation/late.
       - The exact "Next Step" (who to email, which form to fill).

    ### RESPONSE FORMAT:
    - **Policy Reference**: (Which document you are quoting)
    - **Status/Assessment**: (e.g., "You are currently 5 days past the deadline")
    - **⚠️ Conflict Alert**: (Only if policies disagree)
    - **Action Plan**: (Numbered list of steps)
    TEXT;
    }

    public function prompt($message)
    {
        // 1. Search for documents
        $documents = $this->getRelevantPolicies($message);

        // 2. If we found documents, inject them into the history as context
        if (!empty($documents)) {
            $context = "### Context\nUse these snippets to answer the user:\n\n" . $documents;

            // This adds the context specifically for this turn
            $this->chatHistory()->addMessage(new DeveloperMessage($context));
        }

        return $message;
    }

    private function getRelevantPolicies(string $query): string
    {
        // Cache the embedding for 24 hours based on the md5 of the query
        $cacheKey = 'embed_' . md5($query);

        $embeddingValues = cache()->remember($cacheKey, now()->addDay(), function() use ($query) {
            $response = Gemini::embeddingModel('gemini-embedding-001')
                ->embedContent($query, taskType: TaskType::RETRIEVAL_QUERY, outputDimensionality: 768);
            return $response->embedding->values;
        });

        $vectorString = '[' . implode(',', $embeddingValues) . ']';

//        $chunks = DocumentChunk::query()
//            ->join('documents', 'document_chunks.document_id', '=', 'documents.id')
//            ->select('document_chunks.content', 'documents.title')
//            ->orderByRaw("embedding <=> ?::vector", [$vectorString])
//            ->limit(3)
//            ->get();

        // 2. Settings
        $threshold = 0.45;

        // 3. Hybrid Query
        // We use a CTE or a complex select to combine semantic distance and keyword matching
        // Use the model scope
        $chunks = DocumentChunk::with('document')
            ->hybridSearch($vectorString, $query, $threshold)
            ->limit(3)
            ->get();


        if ($chunks->isEmpty()) {
            return "NOTICE: No relevant policy sections were found in the handbook for this specific inquiry.";
        }

        // Map results to readable output showing all scores
        return $chunks->map(function($c) {
            $title = $c->document ? $c->document->title : 'Unknown Document';
            $semanticScore = round($c->semantic_score ?? 0, 2);
            $keywordScore = round($c->search_rank ?? 0, 2);
            $hybridScore = round($c->hybrid_score ?? 0, 2);

            return "[Source: {$title}]\n" .
                "Keyword Score: {$keywordScore}, Semantic Score: {$semanticScore}, Hybrid Score: {$hybridScore}\n" .
                "{$c->content}";
        })->implode("\n\n---\n\n");


    }


}
