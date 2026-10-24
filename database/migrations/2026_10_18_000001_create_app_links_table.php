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
        Schema::create('app_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_lock')->default(1)->unique('app_links_singleton_lock_unique');
            $table->string('google_app_link', 500)->nullable();
            $table->string('apple_app_store_link', 500)->nullable();
            $table->string('app_gallery_link', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_links');
    }
};
