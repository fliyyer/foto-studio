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
            if (! Schema::hasColumn('addons', 'package_id')) {
                $table->foreignId('package_id')->nullable()->after('id')->constrained('packages')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            if (Schema::hasColumn('addons', 'package_id')) {
                $table->dropConstrainedForeignId('package_id');
            }
        });
    }
};
