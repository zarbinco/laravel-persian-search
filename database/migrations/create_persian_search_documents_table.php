<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('persian-search.index.table', 'persian_search_documents'), function (Blueprint $table): void {
            $table->id();
            $table->string('searchable_type')->index();
            $table->string('searchable_id')->index();
            $table->string('locale')->default('')->index();
            $table->string('title')->nullable();
            $table->longText('content');
            $table->json('tokens')->nullable();
            $table->json('fields')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['searchable_type', 'searchable_id', 'locale'],
                'persian_search_documents_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('persian-search.index.table', 'persian_search_documents'));
    }
};
