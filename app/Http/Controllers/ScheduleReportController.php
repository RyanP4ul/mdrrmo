<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleReportController extends Controller
{
    public function __invoke(Request $request, ServiceSchedule $schedule): Response
    {
        $schedule->loadMissing([
            'resident:id,first_name,last_name,contact_number',
            'team.members',
            'acknowledgedBy',
            'driverTicket.driver:id,first_name,last_name',
        ]);

        $role = $request->routeIs('admin.*') ? 'admin' : 'dispatcher';
        $ticket = $schedule->driverTicket;

        return Inertia::render('Reports/ScheduleShow', [
            'schedule' => [
                'id' => $schedule->id,
                'reference' => 'SCH-'.str_pad((string) $schedule->id, 5, '0', STR_PAD_LEFT),
                'service_type' => $schedule->service_type,
                'service_details' => $schedule->service_details,
                'preferred_date' => $schedule->preferred_date?->toDateString(),
                'preferred_time' => Carbon::parse($schedule->preferred_time)->format('H:i'),
                'address' => $schedule->address,
                'destination_address' => $schedule->destination_address,
                'notes' => $schedule->notes,
                'status' => $schedule->status,
                'decline_reason' => $schedule->decline_reason,
                'resident' => $schedule->resident?->full_name,
                'contact' => $schedule->resident?->contact_number,
                'team_name' => $schedule->team?->team_name,
                'team_members' => $schedule->team?->members
                    ? $schedule->team->members
                        ->where('status', 'active')
                        ->map(fn ($member): array => [
                            'id' => $member->id,
                            'name' => $member->name,
                            'role' => $member->role ?? 'Member',
                        ])->values()->all()
                    : [],
                'acknowledged_at' => $schedule->acknowledged_at?->toIso8601String(),
                'started_at' => $schedule->started_at?->toIso8601String(),
                'arrived_at' => $schedule->arrived_at?->toIso8601String(),
                'completed_at' => $schedule->completed_at?->toIso8601String(),
                'created_at' => $schedule->created_at?->toIso8601String(),
            ],
            'driverTicket' => $ticket ? [
                'id' => $ticket->id,
                'driver_name' => $ticket->driver?->full_name,
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'driver_section' => $ticket->driver_section ?? [],
            ] : null,
            'backUrl' => route($role.'.reports.index', ['tab' => 'scheduled']),
            'scheduleUrl' => $role === 'dispatcher' ? route('dispatcher.schedule.index') : null,
        ]);
    }
}
