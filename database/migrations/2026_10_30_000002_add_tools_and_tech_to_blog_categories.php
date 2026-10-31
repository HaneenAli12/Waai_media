<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings key used to record ownership if this migration creates the category.
     */
    private const OWNERSHIP_SETTING_KEY = 'migration_created_category_blogs_tools_tech';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        DB::transaction(function (): void {
            $exists = DB::table('categories')
                ->where('type', 'blogs')
                ->where('slug', 'tools-tech')
                ->lockForUpdate()
                ->exists();

            if (! $exists) {
                $id = DB::table('categories')->insertGetId([
                    'type' => 'blogs',
                    'name_en' => 'Tools & Tech',
                    'name_ar' => 'الأدوات والتقنية',
                    'slug' => 'tools-tech',
                    'parent_id' => null,
                    'sort_order' => 6,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Track ownership in system_settings if the table exists
                if (Schema::hasTable('system_settings')) {
                    DB::table('system_settings')->updateOrInsert(
                        ['key' => self::OWNERSHIP_SETTING_KEY],
                        [
                            'value' => (string) $id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        DB::transaction(function (): void {
            $category = DB::table('categories')
                ->where('type', 'blogs')
                ->where('slug', 'tools-tech')
                ->lockForUpdate()
                ->first();

            if (! $category) {
                return;
            }

            // Only rollback if this migration was the creator of this category
            if (Schema::hasTable('system_settings')) {
                $createdId = DB::table('system_settings')
                    ->where('key', self::OWNERSHIP_SETTING_KEY)
                    ->value('value');

                if ($createdId === null || (int) $createdId !== (int) $category->id) {
                    // Category was pre-existing or created elsewhere; do NOT delete
                    return;
                }
            }

            // Check if any Blog article references this category before deleting
            if (Schema::hasTable('blogs')) {
                $isReferenced = DB::table('blogs')
                    ->where('category_id', $category->id)
                    ->exists();

                if ($isReferenced) {
                    // Fail safely to prevent accidental data inconsistency or cascade breakage
                    throw new RuntimeException(
                        "Cannot rollback category 'tools-tech' (ID: {$category->id}) because it is currently referenced by one or more Blog articles."
                    );
                }
            }

            DB::table('categories')->where('id', $category->id)->delete();

            if (Schema::hasTable('system_settings')) {
                DB::table('system_settings')->where('key', self::OWNERSHIP_SETTING_KEY)->delete();
            }
        });
    }
};
