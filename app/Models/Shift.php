<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Shift extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['start_time' => 'datetime', 'end_time' => 'datetime'];

    public function team(): BelongsTo { return $this->belongsTo(ResponseTeam::class, 'response_team_id'); }
    public function members(): BelongsToMany { return $this->belongsToMany(TeamMember::class, 'shift_team_member'); }
}
