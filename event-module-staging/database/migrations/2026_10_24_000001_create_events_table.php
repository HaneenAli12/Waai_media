<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 255)->nullable();
            $table->string('name_ar', 255)->nullable();
            $table->string('slug', 255)->nullable()->unique();
            $table->string('category_en', 150)->nullable();
            $table->string('category_ar', 150)->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('place_en', 255)->nullable();
            $table->string('place_ar', 255)->nullable();
            $table->dateTime('event_date')->nullable();
            $table->string('cover_image_url', 500)->nullable();
            $table->string('author_name', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            // Query optimization indexes
            $table->index(['status', 'event_date'], 'idx_events_timeline');
            $table->index(['category_en'], 'idx_events_category_en');
            $table->index(['category_ar'], 'idx_events_category_ar');
            $table->index(['created_at', 'id'], 'idx_events_created_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
