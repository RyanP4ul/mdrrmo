<?php

namespace App\Models\Concerns;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

trait Archivable
{
    use SoftDeletes;

    public const RETENTION_DAYS = 30;

    public function initializeArchivable(): void
    {
        $this->mergeCasts([
            'deleted_at' => 'datetime',
            'purge_scheduled_at' => 'datetime',
        ]);
    }

    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->onlyTrashed()->whereNull('purge_scheduled_at');
    }

    public function scopePendingPurge(Builder $query): Builder
    {
        return $query->onlyTrashed()->whereNotNull('purge_scheduled_at');
    }

    public function scopeDueForPurge(Builder $query, ?CarbonInterface $asOf = null): Builder
    {
        return $query->onlyTrashed()
            ->whereNotNull('purge_scheduled_at')
            ->where('purge_scheduled_at', '<=', $asOf ?? now());
    }

    public function archiveBy(?User $actor): void
    {
        $this->forceFill([
            'purge_scheduled_at' => null,
            'archived_by_type' => $actor?->getMorphClass(),
            'archived_by_id' => $actor?->getKey(),
            'archived_by_name' => $actor?->full_name,
            'purge_requested_by_type' => null,
            'purge_requested_by_id' => null,
            'purge_requested_by_name' => null,
        ]);

        $this->delete();
    }

    public function schedulePurgeBy(?User $actor, int $days = 30): void
    {
        $this->forceFill([
            'purge_scheduled_at' => now()->addDays($days),
            'purge_requested_by_type' => $actor?->getMorphClass(),
            'purge_requested_by_id' => $actor?->getKey(),
            'purge_requested_by_name' => $actor?->full_name,
        ])->save();
    }

    public function cancelPurge(): void
    {
        $this->forceFill([
            'purge_scheduled_at' => null,
            'purge_requested_by_type' => null,
            'purge_requested_by_id' => null,
            'purge_requested_by_name' => null,
        ])->save();
    }

    public function restoreFromArchive(): void
    {
        $this->forceFill([
            'purge_scheduled_at' => null,
            'archived_by_type' => null,
            'archived_by_id' => null,
            'archived_by_name' => null,
            'purge_requested_by_type' => null,
            'purge_requested_by_id' => null,
            'purge_requested_by_name' => null,
        ]);

        $this->restore();
    }

    public function isPendingPurge(): bool
    {
        return $this->trashed() && $this->purge_scheduled_at !== null;
    }

    public function daysUntilPurge(?CarbonInterface $asOf = null): ?int
    {
        if (! $this->purge_scheduled_at) {
            return null;
        }

        $now = $asOf ?? now();
        if ($this->purge_scheduled_at->lessThanOrEqualTo($now)) {
            return 0;
        }

        $seconds = $now->diffInSeconds($this->purge_scheduled_at);

        return (int) ceil($seconds / 86400);
    }
}
