<?php

namespace App\Services;

use App\Events\VehicleLocationUpdated;
use App\Models\Account;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ArchiveService
{
    public const RETENTION_DAYS = 30;

    public const TYPE_LABELS = [
        'reports' => 'Emergency Reports',
        'users' => 'Users',
        'vehicles' => 'Vehicles',
        'teams' => 'Response Teams',
        'incident-types' => 'Incident Types',
    ];

    /**
     * @param  array{type?: string|null, state?: string|null, search?: string|null, page?: int|string|null}  $filters
     * @return array{items: LengthAwarePaginator, counts: array<string, int>}
     */
    public function list(array $filters, int $perPage = 10, string $path = '/admin/archives', array $query = []): array
    {
        $selectedType = isset($filters['type']) && array_key_exists($filters['type'], self::TYPE_LABELS)
            ? $filters['type']
            : '';
        $state = in_array($filters['state'] ?? null, ['archived', 'pending_deletion'], true)
            ? $filters['state']
            : '';
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        $allEntries = $this->collectAllArchivedEntries();

        $counts = [
            'all' => $allEntries->count(),
            'archived' => $allEntries->where('status', 'archived')->count(),
            'pending_deletion' => $allEntries->where('status', 'pending_deletion')->count(),
        ];
        foreach (array_keys(self::TYPE_LABELS) as $typeKey) {
            $counts[$typeKey] = $allEntries->where('type', $typeKey)->count();
        }

        $filtered = $allEntries
            ->when($selectedType !== '', fn (Collection $items) => $items->where('type', $selectedType))
            ->when($state !== '', fn (Collection $items) => $items->where('status', $state))
            ->when($search !== '', function (Collection $items) use ($search): Collection {
                return $items->filter(function (array $item) use ($search): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $item['title'],
                        $item['subtitle'],
                        $item['type_label'],
                        $item['archived_by'],
                    ])));

                    return str_contains($haystack, $search);
                });
            })
            ->sort(function (array $a, array $b): int {
                if ($a['status'] !== $b['status']) {
                    return $a['status'] === 'pending_deletion' ? -1 : 1;
                }
                if ($a['status'] === 'pending_deletion') {
                    return strcmp((string) $a['purge_scheduled_at'], (string) $b['purge_scheduled_at']);
                }

                return strcmp((string) $b['archived_at'], (string) $a['archived_at']);
            })
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = $filtered->count();
        $slice = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => $path, 'query' => $query]
        );

        return [
            'items' => $paginator,
            'counts' => $counts,
        ];
    }

    public function resolveArchivedRecord(string $type, string $key): ?Model
    {
        return match ($type) {
            'reports' => ctype_digit($key) ? EmergencyReport::onlyTrashed()->find((int) $key) : null,
            'users' => ($user = Account::findByKeyWithTrashed($key))?->trashed() ? $user : null,
            'vehicles' => ctype_digit($key) ? Vehicle::onlyTrashed()->find((int) $key) : null,
            'teams' => ctype_digit($key) ? ResponseTeam::onlyTrashed()->find((int) $key) : null,
            'incident-types' => ctype_digit($key) ? IncidentType::onlyTrashed()->find((int) $key) : null,
            default => null,
        };
    }

    public function archive(Model $record, ?User $actor = null): void
    {
        if ($record instanceof EmergencyReport) {
            $this->archiveReport($record, $actor);

            return;
        }
        if ($record instanceof User) {
            $this->archiveUser($record, $actor);

            return;
        }
        if ($record instanceof Vehicle) {
            $this->archiveVehicle($record, $actor);

            return;
        }
        if ($record instanceof ResponseTeam) {
            $this->archiveTeam($record, $actor);

            return;
        }
        if ($record instanceof IncidentType) {
            $this->archiveIncidentType($record, $actor);

            return;
        }

        throw new \InvalidArgumentException('Unsupported archivable model: '.$record::class);
    }

    public function scheduleDeletion(Model $record, ?User $actor = null, int $days = self::RETENTION_DAYS): void
    {
        if (! method_exists($record, 'schedulePurgeBy') || ! $record->trashed()) {
            throw ValidationException::withMessages([
                'archive' => 'Only archived records can be scheduled for permanent deletion.',
            ]);
        }

        if ($record->isPendingPurge()) {
            return;
        }

        $record->schedulePurgeBy($actor, $days);
        $meta = $this->describeRecord($record);
        Audit::record("SCHEDULE_DELETE_{$meta['audit_suffix']}", "Scheduled {$meta['label']} for permanent deletion in {$days} days");
    }

    public function cancelDeletion(Model $record): void
    {
        if (! method_exists($record, 'cancelPurge') || ! $record->trashed()) {
            throw ValidationException::withMessages([
                'archive' => 'Record is not in Archives.',
            ]);
        }

        if (! $record->isPendingPurge()) {
            return;
        }

        $record->cancelPurge();
        $meta = $this->describeRecord($record);
        Audit::record("CANCEL_DELETE_{$meta['audit_suffix']}", "Cancelled 30-day permanent deletion for {$meta['label']}");
    }

    public function restore(Model $record): void
    {
        if (! method_exists($record, 'restoreFromArchive') || ! $record->trashed()) {
            throw ValidationException::withMessages([
                'archive' => 'Record is not in Archives.',
            ]);
        }

        $meta = $this->describeRecord($record);
        $record->restoreFromArchive();
        Audit::record("RESTORE_{$meta['audit_suffix']}", "Restored {$meta['label']} from Archives");
    }

    /**
     * Permanently delete all archived records whose 30-day retention window has elapsed.
     *
     * @return array{deleted: int, skipped: int}
     */
    public function purgeDueRecords(?CarbonInterface $asOf = null): array
    {
        $cutoff = $asOf ?? now();
        $deleted = 0;
        $skipped = 0;

        $models = [
            EmergencyReport::class,
            ...Account::classes(),
            Vehicle::class,
            ResponseTeam::class,
            IncidentType::class,
        ];

        foreach ($models as $modelClass) {
            /** @var Collection<int, Model> $due */
            $due = $modelClass::dueForPurge($cutoff)->get();

            foreach ($due as $record) {
                if ($record instanceof IncidentType && $record->reports()->withTrashed()->exists()) {
                    $skipped++;

                    continue;
                }

                $meta = $this->describeRecord($record);

                DB::transaction(function () use ($record): void {
                    $this->purgeAssociatedAssets($record);
                    $record->forceDelete();
                });

                Audit::record("PERMANENT_DELETE_{$meta['audit_suffix']}", "Permanently deleted {$meta['label']} after 30-day archive retention");
                $deleted++;
            }
        }

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
        ];
    }

    private function archiveReport(EmergencyReport $report, ?User $actor): void
    {
        if (! in_array($report->status, ['resolved', 'invalid'], true)) {
            throw ValidationException::withMessages([
                'report' => 'Only resolved or invalid reports can be archived.',
            ]);
        }

        $report->archiveBy($actor);
        Audit::record('ARCHIVE_REPORT', "Archived report {$report->reference}");
    }

    private function archiveUser(User $user, ?User $actor): void
    {
        if ($actor && Account::keyFor($user) === Account::keyFor($actor)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot archive your own account.',
            ]);
        }

        $name = $user->full_name;
        DB::transaction(function () use ($user, $actor): void {
            $user = Account::lockForStatusChange($user);
            if ($user->status !== 'inactive') {
                throw ValidationException::withMessages(['user' => 'Deactivate this account before moving it to Archives.']);
            }
            if ($user instanceof DriverResponderUser && $user->hasActiveEmergencyDispatch()) {
                throw ValidationException::withMessages(['user' => 'Cannot archive a responder assigned to an active incident.']);
            }
            $user->tokens()->delete();
            if ($user instanceof DriverResponderUser) {
                DriverLocation::where('driver_id', $user->getKey())->delete();
            }
            $user->archiveBy($actor);
        }, 3);

        Audit::record('ARCHIVE_USER', "Archived user {$name}");
    }

    private function archiveVehicle(Vehicle $vehicle, ?User $actor): void
    {
        DB::transaction(function () use ($vehicle, $actor): void {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->assigned_report_id !== null) {
                throw ValidationException::withMessages([
                    'vehicle' => 'Cannot archive a vehicle assigned to an active incident.',
                ]);
            }

            if ($locked->response_team_id) {
                $team = ResponseTeam::query()->lockForUpdate()->find($locked->response_team_id);
                if ($team && $team->active_vehicle_id === $locked->id) {
                    $fallback = Vehicle::query()
                        ->where('response_team_id', $team->id)
                        ->where('id', '!=', $locked->id)
                        ->where('status', 'available')
                        ->orderBy('id')
                        ->first();
                    $team->update(['active_vehicle_id' => $fallback?->id]);
                }
            }

            $plate = $locked->plate_number;
            $locked->archiveBy($actor);
            event(new VehicleLocationUpdated($locked));
            Audit::record('ARCHIVE_VEHICLE', "Archived vehicle {$plate}");
        });
    }

    private function archiveTeam(ResponseTeam $team, ?User $actor): void
    {
        $hasActiveReport = EmergencyReport::query()
            ->where('response_team_id', $team->id)
            ->active()
            ->exists();

        if ($hasActiveReport) {
            throw ValidationException::withMessages([
                'team' => 'Cannot archive a response team assigned to an active incident.',
            ]);
        }

        $hasActiveSchedule = ServiceSchedule::query()
            ->where('response_team_id', $team->id)
            ->whereIn('status', ServiceSchedule::ACTIVE_STATUSES)
            ->exists();

        if ($hasActiveSchedule) {
            throw ValidationException::withMessages([
                'team' => 'Cannot archive a response team assigned to an active service schedule.',
            ]);
        }

        $name = $team->team_name;
        $team->archiveBy($actor);
        Audit::record('ARCHIVE_TEAM', "Archived response team {$name}");
    }

    private function archiveIncidentType(IncidentType $incidentType, ?User $actor): void
    {
        $name = $incidentType->name;
        $incidentType->archiveBy($actor);
        Audit::record('ARCHIVE_INCIDENT_TYPE', "Archived incident type \"{$name}\"");
    }

    private function purgeAssociatedAssets(Model $record): void
    {
        if ($record instanceof User) {
            $record->tokens()->delete();
            if ($record->avatar_path) {
                Storage::disk('local')->delete($record->avatar_path);
            }
            if ($record->id_document_path) {
                Storage::disk('r2')->delete($record->id_document_path);
            }
        } elseif ($record instanceof Vehicle && $record->photo_path) {
            if (! str_starts_with($record->photo_path, 'http://')
                && ! str_starts_with($record->photo_path, 'https://')
                && ! str_starts_with($record->photo_path, '/')) {
                Storage::disk('public')->delete($record->photo_path);
            }
        } elseif ($record instanceof EmergencyReport && $record->photo_path) {
            Storage::disk('r2')->delete($record->photo_path);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectAllArchivedEntries(): Collection
    {
        $entries = collect();

        EmergencyReport::onlyTrashed()
            ->with('incidentType:id,name')
            ->get()
            ->each(function (EmergencyReport $report) use ($entries): void {
                $entries->push($this->formatEntry(
                    type: 'reports',
                    key: (string) $report->id,
                    title: $report->reference,
                    subtitle: trim(implode(' · ', array_filter([
                        $report->incidentType?->name,
                        $report->location,
                        ucfirst($report->status),
                    ]))),
                    record: $report,
                ));
            });

        foreach (Account::classes() as $accountClass) {
            $accountClass::onlyTrashed()->get()->each(function (User $user) use ($entries): void {
                $entries->push($this->formatEntry(
                    type: 'users',
                    key: Account::keyFor($user),
                    title: $user->full_name,
                    subtitle: trim(implode(' · ', array_filter([
                        ucfirst($user->role),
                        $user->email,
                    ]))),
                    record: $user,
                ));
            });
        }

        Vehicle::onlyTrashed()
            ->with('team:id,team_name')
            ->get()
            ->each(function (Vehicle $vehicle) use ($entries): void {
                $entries->push($this->formatEntry(
                    type: 'vehicles',
                    key: (string) $vehicle->id,
                    title: $vehicle->name ? "{$vehicle->name} ({$vehicle->plate_number})" : $vehicle->plate_number,
                    subtitle: trim(implode(' · ', array_filter([
                        ucwords(str_replace('-', ' ', $vehicle->vehicle_type)),
                        $vehicle->team?->team_name,
                    ]))),
                    record: $vehicle,
                ));
            });

        ResponseTeam::onlyTrashed()->get()->each(function (ResponseTeam $team) use ($entries): void {
            $entries->push($this->formatEntry(
                type: 'teams',
                key: (string) $team->id,
                title: $team->team_name,
                subtitle: trim(implode(' · ', array_filter([
                    $team->shift_team ? ucfirst($team->shift_team).' shift' : null,
                    ucfirst($team->status),
                ]))),
                record: $team,
            ));
        });

        IncidentType::onlyTrashed()->get()->each(function (IncidentType $type) use ($entries): void {
            $entries->push($this->formatEntry(
                type: 'incident-types',
                key: (string) $type->id,
                title: $type->name,
                subtitle: trim(implode(' · ', array_filter([
                    'Priority: '.ucfirst($type->priority),
                    $type->description,
                ]))),
                record: $type,
            ));
        });

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(string $type, string $key, string $title, string $subtitle, Model $record): array
    {
        $isPending = method_exists($record, 'isPendingPurge') && $record->isPendingPurge();

        return [
            'archive_key' => "{$type}:{$key}",
            'id' => $key,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'status' => $isPending ? 'pending_deletion' : 'archived',
            'archived_at' => $record->deleted_at?->toIso8601String(),
            'archived_by' => $record->archived_by_name ?? 'System',
            'purge_scheduled_at' => $record->purge_scheduled_at?->toIso8601String(),
            'purge_requested_by' => $record->purge_requested_by_name,
            'days_remaining' => method_exists($record, 'daysUntilPurge') ? $record->daysUntilPurge() : null,
        ];
    }

    /**
     * @return array{audit_suffix: string, label: string}
     */
    private function describeRecord(Model $record): array
    {
        return match (true) {
            $record instanceof EmergencyReport => [
                'audit_suffix' => 'REPORT',
                'label' => "report {$record->reference}",
            ],
            $record instanceof User => [
                'audit_suffix' => 'USER',
                'label' => "user {$record->full_name}",
            ],
            $record instanceof Vehicle => [
                'audit_suffix' => 'VEHICLE',
                'label' => "vehicle {$record->plate_number}",
            ],
            $record instanceof ResponseTeam => [
                'audit_suffix' => 'TEAM',
                'label' => "response team {$record->team_name}",
            ],
            $record instanceof IncidentType => [
                'audit_suffix' => 'INCIDENT_TYPE',
                'label' => "incident type \"{$record->name}\"",
            ],
            default => [
                'audit_suffix' => 'RECORD',
                'label' => class_basename($record).' #'.$record->getKey(),
            ],
        };
    }
}
