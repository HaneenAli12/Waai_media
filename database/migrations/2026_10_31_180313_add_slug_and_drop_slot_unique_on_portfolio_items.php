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
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->string('slug', 255)->nullable()->unique()->after('title_en');
            $table->dropUnique('uniq_portfolio_client_slot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->unique(['client_id', 'category_id', 'slot_subcategory_key'], 'uniq_portfolio_client_slot');
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
