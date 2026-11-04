<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationCall extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(EmergencyReport::class, 'emergency_report_id');
    }

    public function scopeForParticipant(Builder $query, User $user): Builder
    {
        $key = Account::keyFor($user);

        return $query->where(fn (Builder $q) => $q->where('resident_key', $key)
            ->orWhere('dispatcher_key', $key)->orWhere('responder_key', $key));
    }

    public function uidFor(User $user): ?int
    {
        return match (Account::keyFor($user)) {
            $this->resident_key => 1,
            $this->dispatcher_key => 2,
            $this->responder_key => 3,
            default => null,
        };
    }

    public function channel(): string
    {
        return 'mdrrmo-'.$this->id;
    }
}
