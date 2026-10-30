<?php

declare(strict_types=1);

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
        if (Schema::hasTable('news') && Schema::hasColumn('news', 'slug')) {
            Schema::table('news', function (Blueprint $table): void {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('news') && ! Schema::hasColumn('news', 'slug')) {
            Schema::table('news', function (Blueprint $table): void {
                $table->string('slug', 255)->nullable()->unique()->after('category_id');
            });
        }
    }
};
