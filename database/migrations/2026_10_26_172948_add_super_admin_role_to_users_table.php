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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('super_admin', 'admin', 'editor') NOT NULL DEFAULT 'editor'");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 30)->default('editor')->change();
            });
        }

        // Controlled database-level assignment for the designated Super Admin account if it exists
        DB::table('users')
            ->where('email', 'waaimedia@gmail.com')
            ->update(['role' => 'super_admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Demote super_admin back to admin before tightening the enum
        DB::table('users')
            ->where('role', 'super_admin')
            ->update(['role' => 'admin']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'editor'");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 30)->default('editor')->change();
            });
        }
    }
};
