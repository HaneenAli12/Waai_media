<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only - no data is dropped or rewritten:
     *  - client_id: nullable at the database level so rows created before this migration are never given an arbitrary
     *    client (the application requires a client for every create/update/publish). RESTRICT keeps a client with
     *    portfolio work from being deleted.
     *  - cover_image_path: the dedicated portfolio cover (R2 key), separate from work images in portfolio_media.
     *  - slot_subcategory_key: STORED generated column = COALESCE(subcategory_id, 0). A UNIQUE index that included the
     *    nullable subcategory_id would allow unlimited duplicates (NULLs are never equal in MySQL); this column turns
     *    "no subcategory" into a comparable value so (client, category, subcategory-or-none) is truly unique.
     *    Rows with a NULL client_id (legacy) are not constrained, as NULLs are distinct in a unique index.
     */
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained('clients')->restrictOnDelete();
            $table->string('cover_image_path', 500)->nullable()->after('title_ar')->index();
            $table->unsignedBigInteger('slot_subcategory_key')->storedAs('COALESCE(`subcategory_id`, 0)')->after('subcategory_id');

            $table->unique(['client_id', 'category_id', 'slot_subcategory_key'], 'uniq_portfolio_client_slot');
            $table->index(['client_id', 'status', 'published_at'], 'idx_portfolio_client_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropUnique('uniq_portfolio_client_slot');
            $table->dropIndex('idx_portfolio_client_status');
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['slot_subcategory_key', 'cover_image_path']);
        });
    }
};
