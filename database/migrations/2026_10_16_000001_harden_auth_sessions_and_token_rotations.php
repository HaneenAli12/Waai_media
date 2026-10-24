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
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->foreignId('access_token_id')->nullable()->after('token_id')->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('remember_token_id')->nullable()->after('access_token_id')->constrained('personal_access_tokens')->nullOnDelete();
            $table->boolean('is_remember')->default(false)->after('device_name');
            $table->timestamp('original_login_at')->nullable()->after('is_remember');
            $table->timestamp('session_expires_at')->nullable()->index()->after('original_login_at');
            $table->timestamp('last_refreshed_at')->nullable()->after('session_expires_at');
        });

        Schema::create('remember_token_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_session_id')->constrained('user_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->index();
            $table->timestamp('rotated_at');
            $table->timestamp('grace_period_expires_at')->nullable()->index();
            $table->foreignId('descendant_access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_session_id', 'rotated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remember_token_rotations');

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropForeign(['access_token_id']);
            $table->dropForeign(['remember_token_id']);
            $table->dropColumn([
                'access_token_id',
                'remember_token_id',
                'is_remember',
                'original_login_at',
                'session_expires_at',
                'last_refreshed_at',
            ]);
        });
    }
};
