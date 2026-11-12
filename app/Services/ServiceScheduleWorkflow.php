<?php

namespace App\Services;

use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ServiceScheduleWorkflow
{
    public function create(ResidentUser $resident, array $data): ServiceSchedule
    {
        return DB::transaction(function () use ($resident, $data): ServiceSchedule {
            $this->validateResidentSlot($resident, $data);

            return $resident->schedules()->create(array_replace($data, ['status' => 'pending']));
        }, 3);
    }

    public function validateResidentSlot(ResidentUser $resident, array $data, ?int $except = null): void
    {
        ResidentUser::whereKey($resident->id)->lockForUpdate()->firstOrFail();
        $when = Carbon::parse($data['preferred_date'].' '.$data['preferred_time'], config('mobile.timezone'));
        $this->ensure(! $when->isPast(), 'preferred_time', 'Choose a future date and time.');
        $minutes = max(1, (int) config('mobile.service_slot_minutes', 60));
        $existing = $resident->schedules()->when($except, fn ($query) => $query->whereKeyNot($except))
            ->whereIn('status', ['pending', 'acknowledged', ...ServiceSchedule::ACTIVE_STATUSES])
            ->whereDate('preferred_date', '>=', $when->copy()->subMinutes($minutes)->toDateString())
            ->whereDate('preferred_date', '<=', $when->copy()->addMinutes($minutes)->toDateString())->get();
        $this->ensure(! $existing->contains(fn ($other) => abs($other->scheduledAt()->diffInMinutes($when, false)) < $minutes), 'preferred_time', 'You already have a service request within this time slot.');
    }

    public function review(ServiceSchedule $schedule, User $actor, string $decision, ?int $teamId = null, ?string $reason = null): ServiceSchedule
    {
        return DB::transaction(function () use ($schedule, $actor, $decision, $teamId, $reason): ServiceSchedule {
            $record = ServiceSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($record->status === $decision && ($decision !== 'confirmed' || ($record->response_team_id === $teamId && $record->responders()->exists()))) {
                return $record;
            }
            if ($decision === 'completed') {
                return $this->finish($record);
            }
            $canRepairAssignment = $record->status === 'confirmed' && $decision === 'confirmed' && ! $record->responders()->exists();
            $this->ensure(in_array($record->status, ['pending', 'acknowledged'], true) || $canRepairAssignment, 'decision', 'This request has already been processed. Refresh to see its current status.');
            $this->ensure(in_array($decision, ['acknowledged', 'confirmed', 'declined'], true), 'decision', 'Invalid schedule decision.');
            $changes = ['status' => $decision];
            if (! $record->acknowledged_at) {
                $changes += ['acknowledged_by' => $actor->id, 'acknowledged_by_type' => $actor->getMorphClass(), 'acknowledged_at' => now()];
            }
            if ($decision === 'confirmed') {
                $this->ensure($teamId !== null, 'team_id', 'Choose a response team to confirm this request.');
                $this->ensure(! $record->scheduledAt()->isPast(), 'preferred_time', 'This requested time has passed. Ask the resident to submit a new request.');
                $team = ResponseTeam::whereKey($teamId)->lockForUpdate()->first();
                $this->ensure($team?->status === 'active', 'team_id', 'Choose an active response team.');
                $driverIds = $team->members()->where('status', 'active')->whereNotNull('user_id')->pluck('user_id');
                $drivers = DriverResponderUser::whereIn('id', $driverIds)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
                $this->ensure($drivers->isNotEmpty(), 'team_id', 'Choose an active team with an active linked driver/responder.');
                $minutes = max(1, (int) config('mobile.service_slot_minutes', 60));
                $conflicts = ServiceSchedule::whereKeyNot($record->id)->whereIn('status', ServiceSchedule::ACTIVE_STATUSES)
                    ->whereDate('preferred_date', '>=', $record->scheduledAt()->subMinutes($minutes)->toDateString())
                    ->whereDate('preferred_date', '<=', $record->scheduledAt()->addMinutes($minutes)->toDateString())
                    ->where(fn ($query) => $query->where('response_team_id', $team->id)->orWhereHas('responders', fn ($query) => $query->whereIn('drivers.id', $drivers->modelKeys())))
                    ->get();
                $this->ensure(! $conflicts->contains(fn ($other) => abs($other->scheduledAt()->diffInMinutes($record->scheduledAt(), false)) < $minutes), 'team_id', "This team or one of its responders has a booking within {$minutes} minutes of this time.");
                if ($record->scheduledAt()->lessThanOrEqualTo(now(config('mobile.timezone'))->addMinutes($minutes))) {
                    $busy = EmergencyReport::whereIn('status', ['acknowledged', 'dispatched'])
                        ->where(fn ($query) => $query->where('response_team_id', $team->id)->orWhereIn('assigned_driver_id', $drivers->modelKeys()))->exists();
                    $this->ensure(! $busy, 'team_id', 'This team or one of its responders is handling an active emergency.');
                }
                $changes['response_team_id'] = $team->id;
                $record->update($changes);
                foreach ($drivers as $driver) {
                    DriverNotification::scheduleReceipt($driver->id, $record, $team->team_name);
                }
                $this->notifyResident($record, 'schedule_confirmed', 'Schedule confirmed', "Your {$record->service_type} on {$record->preferred_date->toFormattedDateString()} is confirmed. {$team->team_name} has been assigned.");
            } else {
                if ($decision === 'declined') {
                    $this->ensure(trim($reason ?? '') !== '', 'reason', 'Enter a reason for declining this request.');
                    $changes['decline_reason'] = $reason;
                }
                $record->update($changes);
                $this->notifyResident($record, $decision === 'declined' ? 'schedule_cancelled' : 'general', 'Schedule '.$decision,
                    $decision === 'declined' ? "Reason: {$reason}" : 'Your service request has been acknowledged. A response team will be assigned next.');
            }
            Audit::record('UPDATE_SCHEDULE', "Schedule {$record->id} {$decision}");

            return $record;
        }, 3);
    }

    public function progress(ServiceSchedule $schedule, User $actor, string $status): ServiceSchedule
    {
        return DB::transaction(function () use ($schedule, $actor, $status): ServiceSchedule {
            $record = ServiceSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->responders()->whereKey($actor->id)->exists(), 404);
            if ($record->status === $status) {
                return $record;
            }
            if ($status === 'completed') {
                return $this->finish($record);
            }
            $expected = ['en-route' => 'confirmed', 'on-scene' => 'en-route'];
            $this->ensure(isset($expected[$status]) && $record->status === $expected[$status], 'status', 'Refresh this service before changing its status.');
            $this->ensure($record->preferred_date->toDateString() <= now(config('mobile.timezone'))->toDateString(), 'status', 'This service is scheduled for a future date.');
            if ($status === 'en-route') {
                $team = ResponseTeam::whereKey($record->response_team_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
                if ($team->member_roles_enabled) {
                    $this->ensure($team->members()->where('user_id', $actor->id)->where('status', 'active')->where('role', 'Driver')->exists(), 'status', 'Only the team Driver can start a service trip.');
                }
                $responders = $record->responders()->pluck('drivers.id');
                DriverResponderUser::whereIn('id', $responders)->orderBy('id')->lockForUpdate()->get();
                $this->ensure(! EmergencyReport::whereIn('assigned_driver_id', $responders)->whereIn('status', ['acknowledged', 'dispatched'])->exists(), 'status', 'A responder has an active emergency assignment. Complete it before starting this service.');
                $this->ensure(! ServiceSchedule::whereKeyNot($record->id)->whereIn('status', ['en-route', 'on-scene'])->whereHas('responders', fn ($query) => $query->whereIn('drivers.id', $responders))->exists(), 'status', 'Complete the current service before starting another.');
            }
            $record->update(['status' => $status, $status === 'en-route' ? 'started_at' : 'arrived_at' => now()]);
            $this->notifyResident($record, 'general', 'Service '.$status, "Your {$record->service_type} response team is {$status}.");
            $this->notifyResponders($record, $status, 'Service '.$status);
            Audit::record('UPDATE_SCHEDULE', "Schedule {$record->id} {$status}");

            return $record;
        }, 3);
    }

    private function finish(ServiceSchedule $record): ServiceSchedule
    {
        if ($record->status === 'completed') {
            return $record;
        }
        $this->ensure(in_array($record->status, ServiceSchedule::ACTIVE_STATUSES, true), 'status', 'Only confirmed assigned services can be completed.');
        $this->ensure($record->response_team_id !== null && $record->responders()->exists(), 'status', 'Assign a response team before completing this service.');
        $this->ensure($record->preferred_date->toDateString() <= now(config('mobile.timezone'))->toDateString(), 'status', 'This service is scheduled for a future date.');
        $record->update(['status' => 'completed', 'completed_at' => now()]);
        $this->notifyResident($record, 'general', 'Schedule completed', "Your {$record->service_type} service on {$record->preferred_date->toFormattedDateString()} has been completed.");
        $this->notifyResponders($record, 'completed', 'Service completed');
        Audit::record('COMPLETE_SCHEDULE', "Schedule {$record->id} completed");

        return $record;
    }

    public function cancel(ServiceSchedule $schedule, User $actor): ServiceSchedule
    {
        return DB::transaction(function () use ($schedule, $actor): ServiceSchedule {
            $record = ServiceSchedule::whereKey($schedule->id)->where('resident_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($record->status === 'cancelled') {
                return $record;
            }
            Gate::forUser($actor)->authorize('cancel', $record);
            $record->update(['status' => 'cancelled']);
            $this->notifyResident($record, 'schedule_cancelled', 'Schedule cancelled', "Your {$record->service_type} request has been cancelled.");
            $this->notifyResponders($record, 'cancelled', 'Service schedule cancelled');
            Audit::record('CANCEL_SCHEDULE', "Schedule {$record->id} cancelled");

            return $record;
        }, 3);
    }

    private function notifyResident(ServiceSchedule $record, string $type, string $title, string $message): void
    {
        ResidentNotification::create(['user_id' => $record->resident_id, 'type' => $type, 'title' => $title, 'message' => $message, 'related_type' => ServiceSchedule::class, 'related_id' => $record->id]);
    }

    private function notifyResponders(ServiceSchedule $record, string $kind, string $title): void
    {
        foreach ($record->responders as $driver) {
            DriverNotification::firstOrCreate(['driver_id' => $driver->id, 'schedule_id' => $record->id, 'kind' => 'schedule_'.$kind], [
                'title' => $title, 'message' => "{$record->service_type} on {$record->preferred_date->toDateString()} at {$record->preferred_time}: {$record->status}.",
            ]);
        }
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
