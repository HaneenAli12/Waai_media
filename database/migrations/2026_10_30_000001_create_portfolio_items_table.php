<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: creates new tables and never touches existing ones. Main categories and subcategories live in
     * the existing `categories` table (type = 'portfolio'); items reference them by id and are never matched by
     * translated names.
     */
    public function up(): void
    {
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('title_en', 255)->nullable();
            $table->string('title_ar', 255)->nullable();
            $table->string('status', 20)->default('draft');
            // Web Design project links (link_3 is optional); unused for every other category
            $table->string('link_1', 500)->nullable();
            $table->string('link_2', 500)->nullable();
            $table->string('link_3', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'id'], 'idx_portfolio_created_id');
            $table->index(['status', 'published_at', 'id'], 'idx_portfolio_status_published');
            $table->index(['category_id', 'subcategory_id'], 'idx_portfolio_category_sub');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_items');
    }
};
