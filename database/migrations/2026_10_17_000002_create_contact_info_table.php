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
        Schema::create('contact_info', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_lock')->default(1)->unique();
            $table->string('phone_1', 50);
            $table->string('phone_2', 50)->nullable();
            $table->string('phone_3', 50)->nullable();
            $table->string('official_email', 255);
            $table->string('facebook', 500);
            $table->string('instagram', 500);
            $table->string('youtube', 500);
            $table->string('linkedin', 500);
            $table->string('tiktok', 500);
            $table->string('whatsapp', 500);
            $table->text('complete_address');
            $table->text('google_maps_url');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_info');
    }
};
