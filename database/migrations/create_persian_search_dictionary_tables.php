<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('persian-search.spelling.connection') ?: config('persian-search.index.connection');
        $termsTable = $this->tableName(
            config('persian-search.spelling.terms_table', 'persian_search_dictionary_terms'),
        );
        $deletesTable = $this->tableName(
            config('persian-search.spelling.deletes_table', 'persian_search_dictionary_deletes'),
        );

        Schema::connection($connection)->create($termsTable, function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 32);
            $table->string('term', 191);
            $table->string('normalized_term', 191);
            $table->unsignedInteger('document_frequency')->default(0);
            $table->unsignedInteger('title_frequency')->default(0);
            $table->unsignedInteger('keyword_frequency')->default(0);
            $table->unsignedInteger('excerpt_frequency')->default(0);
            $table->unsignedInteger('content_frequency')->default(0);
            $table->boolean('is_protected')->default(false);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'normalized_term'], 'ps_dictionary_term_unique');
            $table->index(['locale', 'document_frequency'], 'ps_dictionary_locale_frequency');
            $table->index(['locale', 'is_protected'], 'ps_dictionary_locale_protected');
            $table->index('indexed_at', 'ps_dictionary_indexed_at');
        });

        Schema::connection($connection)->create($deletesTable, function (Blueprint $table) use ($termsTable): void {
            $table->id();
            $table->unsignedBigInteger('term_id');
            $table->string('locale', 32);
            $table->string('delete_key', 191);
            $table->unsignedTinyInteger('distance');

            $table->unique(['locale', 'delete_key', 'term_id'], 'ps_dictionary_delete_unique');
            $table->index(['locale', 'delete_key'], 'ps_dictionary_delete_lookup');
            $table->index('term_id', 'ps_dictionary_delete_term');
            $table->foreign('term_id', 'ps_dictionary_delete_term_fk')
                ->references('id')
                ->on($termsTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $connection = config('persian-search.spelling.connection') ?: config('persian-search.index.connection');
        Schema::connection($connection)->dropIfExists(
            $this->tableName(config('persian-search.spelling.deletes_table', 'persian_search_dictionary_deletes')),
        );
        Schema::connection($connection)->dropIfExists(
            $this->tableName(config('persian-search.spelling.terms_table', 'persian_search_dictionary_terms')),
        );
    }

    private function tableName(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Persian search spelling table names must be safe unqualified database identifiers.');
        }

        return $value;
    }
};
