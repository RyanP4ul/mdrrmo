<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchTrip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['returning_at' => 'datetime', 'started_at' => 'datetime', 'on_scene_at' => 'datetime', 'picked_up_at' => 'datetime', 'arrived_at' => 'datetime', 'distance_km' => 'float'];
    }
}
