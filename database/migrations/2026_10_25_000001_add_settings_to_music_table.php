<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('music', function (Blueprint $table) {
            if (! Schema::hasColumn('music', 'is_enabled')) {
                $table->boolean('is_enabled')->default(false)->after('file_size');
            }
            if (! Schema::hasColumn('music', 'is_loop')) {
                $table->boolean('is_loop')->default(false)->after('is_enabled');
            }
        });

        // If the table is completely empty, insert an unconfigured default row 1.
        // If row 1 already exists, its file metadata is completely preserved.
        if (DB::table('music')->count() === 0) {
            DB::table('music')->insert([
                'id' => 1,
                'file_name' => null,
                'file_path' => null,
                'file_url' => null,
                'mime_type' => null,
                'file_size' => null,
                'is_enabled' => false,
                'is_loop' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('music', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('music', 'is_loop')) {
                $columnsToDrop[] = 'is_loop';
            }
            if (Schema::hasColumn('music', 'is_enabled')) {
                $columnsToDrop[] = 'is_enabled';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
