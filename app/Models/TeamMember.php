<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TeamMember extends Model
{
    protected $guarded = ['id'];

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_team_member');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ResponseTeam::class, 'response_team_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(DriverResponderUser::class, 'user_id');
    }
}
