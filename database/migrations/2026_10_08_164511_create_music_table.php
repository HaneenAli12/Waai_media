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
        Schema::create('music', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->string('file_name', 255)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('file_url', 500)->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->unsignedInteger('file_size')->nullable()->comment('Binary file size in bytes (actual uploaded file size)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('music');
    }
};
