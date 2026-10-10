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
        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('otp_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('reset_token_hash', 64)->nullable()->index();
            $table->timestamp('reset_token_expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'expires_at']);
            $table->index(['email', 'reset_token_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};
