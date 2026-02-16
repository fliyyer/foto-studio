<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    public const STATUSES = [
        'pending',
        'confirmed',
        'completed',
        'cancelled',
        'expired',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid',
        'paid',
        'failed',
        'refunded',
    ];

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'package_id',
        'voucher_id',
        'booking_date',
        'start_time',
        'end_time',
        'subtotal_price',
        'discount_amount',
        'total_price',
        'status',
        'payment_status',
        'payment_method',
        'payment_reference',
        'payment_expired_at',
        'notes',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'subtotal_price' => 'float',
        'discount_amount' => 'float',
        'total_price' => 'float',
        'payment_expired_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function bookingAddons(): HasMany
    {
        return $this->hasMany(BookingAddon::class);
    }

    public static function bookingStatuses(): array
    {
        return self::STATUSES;
    }

    public static function paymentStatuses(): array
    {
        return self::PAYMENT_STATUSES;
    }
}
