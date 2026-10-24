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
        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_event_cats_active_sort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_categories');
    }
};
