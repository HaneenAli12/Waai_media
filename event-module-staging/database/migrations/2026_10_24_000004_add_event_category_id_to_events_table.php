<?php

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
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('event_category_id')
                ->nullable()
                ->after('category_ar')
                ->constrained('event_categories')
                ->nullOnDelete();

            $table->index(['event_category_id', 'status', 'event_date'], 'idx_events_cat_status_date');
        });

        // Migrate any existing category_en / category_ar text records into event_categories
        if (Schema::hasTable('events') && Schema::hasTable('event_categories')) {
            $existingCategories = DB::table('events')
                ->select('category_en', 'category_ar')
                ->whereNotNull('category_en')
                ->orWhereNotNull('category_ar')
                ->distinct()
                ->get();

            foreach ($existingCategories as $cat) {
                $nameEn = trim((string) ($cat->category_en ?? $cat->category_ar));
                $nameAr = trim((string) ($cat->category_ar ?? $cat->category_en));

                if ($nameEn === '' && $nameAr === '') {
                    continue;
                }

                $baseSlug = Str::slug($nameEn !== '' ? $nameEn : 'event-category');
                if ($baseSlug === '') {
                    $baseSlug = 'event-category';
                }

                // Ensure unique slug
                $slug = $baseSlug;
                $counter = 1;
                while (DB::table('event_categories')->where('slug', $slug)->exists()) {
                    $slug = "{$baseSlug}-{$counter}";
                    $counter++;
                }

                $catId = DB::table('event_categories')->insertGetId([
                    'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                    'name_ar' => $nameAr !== '' ? $nameAr : $nameEn,
                    'slug' => $slug,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('events')
                    ->where(function ($q) use ($cat) {
                        if ($cat->category_en !== null) {
                            $q->where('category_en', $cat->category_en);
                        }
                        if ($cat->category_ar !== null) {
                            $q->where('category_ar', $cat->category_ar);
                        }
                    })
                    ->whereNull('event_category_id')
                    ->update(['event_category_id' => $catId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['event_category_id']);
            $table->dropIndex('idx_events_cat_status_date');
            $table->dropColumn('event_category_id');
        });
    }
};
