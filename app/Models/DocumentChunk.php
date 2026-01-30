<?php

namespace App\Models;

use Pgvector\Laravel\Vector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DocumentChunk extends Model
{
    protected $fillable = ['document_id', 'content', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
    ];

    /**
     * Scope for Hybrid Search (Vector + Full Text + Optional Category)
     *
     * @param Builder $query
     * @param string $vectorString
     * @param string $rawQuery
     * @param float $threshold
     * @param float $keywordWeight
     * @param float $vectorWeight
     * @param string|null $category
     * @return Builder
     */
    public function scopeHybridSearch(
        Builder $query,
        string $vectorString,
        string $rawQuery,
        float $threshold = 0.45,
        float $keywordWeight = 0.7,
        float $vectorWeight = 0.3,
        string $category = null
    ): Builder {
        $query = $query
            ->select('document_chunks.*')
            ->selectRaw("
                ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) AS search_rank,
                (embedding <=> ?::vector) AS distance,
                1 - (embedding <=> ?::vector) AS semantic_score,
                (? * ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) +
                 ? * (1 - (embedding <=> ?::vector))) AS hybrid_score
            ", [
                $rawQuery,
                $vectorString,
                $vectorString,
                $keywordWeight,
                $rawQuery,
                $vectorWeight,
                $vectorString
            ])

            ->where(function ($q) use ($vectorString, $threshold, $rawQuery) {
                $q->whereRaw("(embedding <=> ?::vector) < ?", [$vectorString, $threshold])
                    ->orWhereRaw("to_tsvector('english', content) @@ plainto_tsquery('english', ?)", [$rawQuery]);
            })
            ->orderByDesc('hybrid_score');

        if ($category && $category !== 'General') {
            $query->whereJsonContains('metadata->category', $category);
        }

        return $query;
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
