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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('studios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address');
            $table->string('city');
            $table->time('open_time');
            $table->time('close_time');
            $table->integer('slot_duration');
            $table->integer('max_booking_per_slot');
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained('studios');
            $table->string('name');
            $table->string('category');
            $table->decimal('price', 12, 2);
            $table->integer('duration_minutes');
            $table->text('description');
            $table->integer('max_person');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->string('type');
            $table->text('description');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('package_id')->constrained('packages');
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('total_price', 12, 2);
            $table->string('status');
            $table->string('payment_status');
            $table->string('payment_method');
            $table->string('payment_reference')->nullable();
            $table->timestamp('payment_expired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings');
            $table->foreignId('addon_id')->constrained('addons');
            $table->integer('qty');
            $table->decimal('price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings');
            $table->string('method');
            $table->decimal('amount', 12, 2);
            $table->string('transaction_id');
            $table->string('payment_status');
            $table->timestamp('paid_at')->nullable();
            $table->text('raw_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_addons');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('studios');
        Schema::dropIfExists('customers');
    }
};
