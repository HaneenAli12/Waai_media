<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per media entry of an item:
     *  - image kinds:        image_path set, url null
     *  - press covering:     image_path + its own url (the pair stays in one row)
     *  - video kinds:        url set (video URL), image_path null
     *  - web design:         a single row holding the featured image
     * Video files are never stored.
     */
    public function up(): void
    {
        Schema::create('portfolio_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->string('image_path', 500)->nullable()->index();
            $table->string('url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['portfolio_item_id', 'sort_order', 'id'], 'idx_portfolio_media_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_media');
    }
};
