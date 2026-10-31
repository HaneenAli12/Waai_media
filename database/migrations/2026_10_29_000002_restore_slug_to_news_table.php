<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('news')) {
            return;
        }

        if (! Schema::hasColumn('news', 'slug')) {
            Schema::table('news', function (Blueprint $table): void {
                $table->string('slug', 255)->nullable()->unique()->after('title_en');
            });
        }

        // Backfill missing slugs for any existing News records with a valid Latin English title
        DB::table('news')
            ->select(['id', 'title_en', 'slug'])
            ->whereNull('slug')
            ->whereNotNull('title_en')
            ->orderBy('id')
            ->each(function (object $row): void {
                $trimmed = trim((string) $row->title_en);
                if ($trimmed === '' || ! preg_match('/[a-zA-Z0-9]/', $trimmed)) {
                    return;
                }

                $normalized = Str::slug($trimmed, '-', 'en');
                if ($normalized === '') {
                    return;
                }

                $idSuffix = '-'.$row->id;
                $maxBaseLength = 255 - strlen($idSuffix);
                $base = substr($normalized, 0, $maxBaseLength);
                $base = rtrim($base, '-');
                if ($base === '') {
                    $base = 'news';
                }

                $candidate = $base.$idSuffix;

                // Ensure uniqueness even if a manual/custom slug collided
                $counter = 1;
                while (DB::table('news')->where('slug', $candidate)->where('id', '!=', $row->id)->exists()) {
                    $suffix = "-{$row->id}-{$counter}";
                    $candidate = rtrim(substr($normalized, 0, 255 - strlen($suffix)), '-').$suffix;
                    $counter++;
                }

                DB::table('news')
                    ->where('id', $row->id)
                    ->update(['slug' => $candidate]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('news') && Schema::hasColumn('news', 'slug')) {
            Schema::table('news', function (Blueprint $table): void {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            });
        }
    }
};
