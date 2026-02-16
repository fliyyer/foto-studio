<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Studio extends Model
{
    protected $fillable = [
        'name',
        'address',
        'city',
        'thumbnail',
        'open_time',
        'close_time',
    ];

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
