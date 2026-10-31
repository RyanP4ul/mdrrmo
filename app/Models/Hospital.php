<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['specialization' => 'array', 'lat' => 'float', 'lng' => 'float'];
}
