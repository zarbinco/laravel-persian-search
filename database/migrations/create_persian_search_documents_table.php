<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('persian-search.index.connection'))->create(
            (string) config('persian-search.index.table', 'persian_search_documents'),
            function (Blueprint $table): void {
                $table->id();
                $table->string('partition', 64)->default('default');
                $table->string('source_key', 191);
                $table->string('source_type');
                $table->string('source_id')->nullable();
                $table->string('source_connection')->nullable();
                $table->string('provider_key')->default('eloquent');
                $table->string('locale', 32)->default('und');
                $table->text('title')->nullable();
                $table->text('excerpt')->nullable();
                $table->text('normalized_title')->nullable();
                $table->text('normalized_excerpt')->nullable();
                $table->longText('normalized_keywords')->nullable();
                $table->longText('normalized_content')->nullable();
                $table->json('payload')->nullable();
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->char('document_hash', 64);
                $table->timestamp('source_updated_at')->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();

                $table->unique(['partition', 'source_key', 'locale'], 'ps_docs_identity_unique');
                $table->index('source_key', 'ps_docs_source_key');
                $table->index(['partition', 'locale', 'is_active'], 'ps_docs_partition_locale_active');
                $table->index(['partition', 'source_type', 'locale', 'is_active'], 'ps_docs_partition_type_locale_active');
                $table->index(['source_type', 'source_id'], 'ps_docs_source_type_id');
                $table->index('indexed_at', 'ps_docs_indexed_at');
                $table->index(['provider_key', 'is_active'], 'ps_docs_provider_active');
            },
        );
    }

    public function down(): void
    {
        Schema::connection(config('persian-search.index.connection'))->dropIfExists(
            (string) config('persian-search.index.table', 'persian_search_documents'),
        );
    }
};
