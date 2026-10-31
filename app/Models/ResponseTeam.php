<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResponseTeam extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['specializations' => 'array'];

    public function members(): HasMany  { return $this->hasMany(TeamMember::class); }
    public function shifts(): HasMany   { return $this->hasMany(Shift::class); }
    public function reports(): HasMany  { return $this->hasMany(EmergencyReport::class); }
    public function vehicles(): HasMany { return $this->hasMany(Vehicle::class); }
}
