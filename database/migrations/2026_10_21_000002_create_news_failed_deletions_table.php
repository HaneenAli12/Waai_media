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
        Schema::create('news_failed_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('object_key', 255)->unique();
            $table->string('reason', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_failed_deletions');
    }
};
