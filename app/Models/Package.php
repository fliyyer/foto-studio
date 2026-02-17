<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'studio_id',
        'name',
        'category',
        'background',
        'thumbnail',
        'price',
        'duration_minutes',
        'slot_duration',
        'max_booking_per_slot',
        'description',
        'max_person',
        'is_active',
    ];

    protected $casts = [
        'background' => 'array',
        'is_active' => 'boolean',
    ];

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
