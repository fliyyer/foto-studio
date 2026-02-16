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
        Schema::table('addons', function (Blueprint $table) {
            if (Schema::hasColumn('addons', 'public_id')) {
                $table->dropUnique('addons_public_id_unique');
                $table->dropColumn('public_id');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'public_id')) {
                $table->dropUnique('packages_public_id_unique');
                $table->dropColumn('public_id');
            }
        });

        Schema::table('studios', function (Blueprint $table) {
            if (Schema::hasColumn('studios', 'public_id')) {
                $table->dropUnique('studios_public_id_unique');
                $table->dropColumn('public_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            if (! Schema::hasColumn('studios', 'public_id')) {
                $table->string('public_id', 8)->nullable()->unique()->after('id');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'public_id')) {
                $table->string('public_id', 8)->nullable()->unique()->after('id');
            }
        });

        Schema::table('addons', function (Blueprint $table) {
            if (! Schema::hasColumn('addons', 'public_id')) {
                $table->string('public_id', 8)->nullable()->unique()->after('id');
            }
        });
    }
};
