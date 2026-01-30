<?php

namespace App\Models;

use Pgvector\Laravel\Vector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DocumentChunk extends Model
{
    protected $fillable = ['document_id', 'content', 'embedding'];

    protected $casts = [
        'embedding' => Vector::class,
    ];

    /**
     * Scope for Hybrid Search (Vector + Full Text)
     */
    public function scopeHybridSearch(Builder $query, string $vectorString, string $rawQuery, float $threshold = 0.45)
    {
        return $query
            ->select('document_chunks.*')
            // Calculate Distance (Vector) and Rank (Text)
            ->selectRaw("
                (embedding <=> ?::vector) as distance,
                ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) as search_rank
            ", [$vectorString, $rawQuery])
            // Thresholding: Must be semantically close OR contain exact keywords
            ->where(function ($q) use ($vectorString, $threshold, $rawQuery) {
                $q->whereRaw("(embedding <=> ?::vector) < ?", [$vectorString, $threshold])
                    ->orWhereRaw("to_tsvector('english', content) @@ plainto_tsquery('english', ?)", [$rawQuery]);
            })
            // Re-Ranking logic
            ->orderByDesc('search_rank') // Keyword matches first
            ->orderBy('distance');       // Then closest semantic matches
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
