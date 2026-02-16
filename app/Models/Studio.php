<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
