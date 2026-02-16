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
        Schema::table('studios', function (Blueprint $table) {
            if (! Schema::hasColumn('studios', 'thumbnail')) {
                $table->string('thumbnail')->nullable()->after('city');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'slot_duration')) {
                $table->integer('slot_duration')->after('duration_minutes');
            }

            if (! Schema::hasColumn('packages', 'max_booking_per_slot')) {
                $table->integer('max_booking_per_slot')->after('slot_duration');
            }
        });

        Schema::table('studios', function (Blueprint $table) {
            if (Schema::hasColumn('studios', 'slot_duration')) {
                $table->dropColumn('slot_duration');
            }

            if (Schema::hasColumn('studios', 'max_booking_per_slot')) {
                $table->dropColumn('max_booking_per_slot');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            if (! Schema::hasColumn('studios', 'slot_duration')) {
                $table->integer('slot_duration')->after('close_time');
            }

            if (! Schema::hasColumn('studios', 'max_booking_per_slot')) {
                $table->integer('max_booking_per_slot')->after('slot_duration');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'max_booking_per_slot')) {
                $table->dropColumn('max_booking_per_slot');
            }

            if (Schema::hasColumn('packages', 'slot_duration')) {
                $table->dropColumn('slot_duration');
            }
        });

        Schema::table('studios', function (Blueprint $table) {
            if (Schema::hasColumn('studios', 'thumbnail')) {
                $table->dropColumn('thumbnail');
            }
        });
    }
};
