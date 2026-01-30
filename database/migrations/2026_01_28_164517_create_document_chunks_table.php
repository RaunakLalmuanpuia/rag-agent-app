<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->text('content');

            // 1. Create the vector column first
            $table->vector('embedding', 768)->nullable();
            $table->json('metadata')->nullable()->after('content');


            $table->timestamps();
        });

        // 2. Create the HNSW index AFTER the table and column are created
        DB::statement('CREATE INDEX ON document_chunks USING hnsw (embedding vector_cosine_ops)');

        // NEW: Full-Text Search Index (GIN)
        // This allows PostgreSQL to search keywords instantly
        DB::statement("CREATE INDEX content_search_idx ON document_chunks USING GIN (to_tsvector('english', content))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
