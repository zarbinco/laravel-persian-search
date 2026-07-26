<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('persian-search.contextual.connection')
            ?: config('persian-search.spelling.connection')
            ?: config('persian-search.index.connection');
        $ngrams = $this->tableName(
            config('persian-search.contextual.ngrams_table', 'persian_search_dictionary_ngrams'),
        );
        $staging = $this->tableName(
            config('persian-search.contextual.ngram_staging_table', 'persian_search_dictionary_ngram_staging'),
        );
        $builds = $this->tableName(
            config('persian-search.contextual.builds_table', 'persian_search_contextual_builds'),
        );
        if (count(array_unique([$ngrams, $staging, $builds])) !== 3) {
            throw new InvalidArgumentException('Persian search contextual table names must be distinct.');
        }
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($ngrams)) {
            $schema->create($ngrams, function (Blueprint $table): void {
                $table->id();
                $table->string('locale', 32);
                $table->unsignedTinyInteger('gram_size');
                $table->char('gram_hash', 64);
                $table->text('normalized_gram');
                $table->string('first_term', 191);
                $table->string('second_term', 191);
                $table->unsignedInteger('document_frequency')->default(0);
                $table->unsignedInteger('title_frequency')->default(0);
                $table->unsignedInteger('keyword_frequency')->default(0);
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();

                $table->unique(['locale', 'gram_size', 'gram_hash'], 'ps_context_ngram_unique');
                $table->index(['locale', 'gram_size', 'document_frequency'], 'ps_context_ngram_frequency');
                $table->index('indexed_at', 'ps_context_ngram_indexed_at');
            });
        }

        if (! $schema->hasTable($staging)) {
            $schema->create($staging, function (Blueprint $table): void {
                $table->id();
                $table->char('build_token', 36);
                $table->string('locale', 32);
                $table->unsignedTinyInteger('gram_size');
                $table->char('gram_hash', 64);
                $table->text('normalized_gram');
                $table->string('first_term', 191);
                $table->string('second_term', 191);
                $table->unsignedInteger('document_frequency')->default(0);
                $table->unsignedInteger('title_frequency')->default(0);
                $table->unsignedInteger('keyword_frequency')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index(['build_token', 'locale'], 'ps_context_stage_build_locale');
                $table->index(['build_token', 'gram_hash'], 'ps_context_stage_build_hash');
            });
        }

        if (! $schema->hasTable($builds)) {
            $schema->create($builds, function (Blueprint $table): void {
                $table->string('locale', 32)->primary();
                $table->char('dictionary_generation', 36);
                $table->char('ngram_generation', 36)->nullable();
                $table->unsignedInteger('term_count')->default(0);
                $table->unsignedInteger('document_count')->default(0);
                $table->unsignedInteger('ngram_count')->default(0);
                $table->timestamp('dictionary_indexed_at')->nullable();
                $table->timestamp('ngram_indexed_at')->nullable();
                $table->timestamps();

                $table->index('dictionary_indexed_at', 'ps_context_build_dictionary_at');
                $table->index('ngram_indexed_at', 'ps_context_build_ngram_at');
            });
        }
    }

    public function down(): void
    {
        $connection = config('persian-search.contextual.connection')
            ?: config('persian-search.spelling.connection')
            ?: config('persian-search.index.connection');
        Schema::connection($connection)->dropIfExists(
            $this->tableName(
                config('persian-search.contextual.builds_table', 'persian_search_contextual_builds'),
            ),
        );
        Schema::connection($connection)->dropIfExists(
            $this->tableName(
                config('persian-search.contextual.ngram_staging_table', 'persian_search_dictionary_ngram_staging'),
            ),
        );
        Schema::connection($connection)->dropIfExists(
            $this->tableName(
                config('persian-search.contextual.ngrams_table', 'persian_search_dictionary_ngrams'),
            ),
        );
    }

    private function tableName(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Persian search contextual table names must be safe unqualified database identifiers.');
        }

        return $value;
    }
};
