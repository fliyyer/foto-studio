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
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('package_id')->constrained('vouchers');
            $table->decimal('subtotal_price', 12, 2)->default(0)->after('end_time');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['subtotal_price', 'discount_amount']);
        });
    }
};
