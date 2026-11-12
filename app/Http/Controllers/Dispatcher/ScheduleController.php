<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Services\ServiceScheduleWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'acknowledged', 'confirmed', 'declined', 'completed', 'cancelled', 'en-route', 'on-scene'])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $f['month'] ?? now(config('mobile.timezone'))->format('Y-m'));

        return Inertia::render('Dispatcher/Schedule', [
            'slotMinutes' => max(1, (int) config('mobile.service_slot_minutes', 60)),
            'filters' => $f, 'month' => $month->format('Y-m'),
            'teams' => ResponseTeam::query()->where('status', 'active')
                ->whereHas('members', fn ($query) => $query->where('status', 'active')->whereNotNull('user_id')
                    ->whereHas('user', fn ($user) => $user->where('status', 'active')))
                ->orderBy('team_name')->get(['id', 'team_name']),
            'schedules' => ServiceSchedule::with('resident:id,first_name,last_name,contact_number', 'team:id,team_name')->withCount('responders')
                ->whereBetween('preferred_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('preferred_date')->orderBy('preferred_time')->get()
                ->map(fn ($s) => $s->only('id', 'service_type', 'preferred_time', 'address', 'notes', 'status', 'decline_reason') + [
                    'team_name' => $s->team?->team_name, 'needs_assignment' => $s->responders_count === 0, 'date' => $s->preferred_date->toDateString(), 'resident' => $s->resident->full_name, 'contact' => $s->resident->contact_number]),
        ]);
    }

    public function acknowledge(Request $request, ServiceSchedule $schedule, ServiceScheduleWorkflow $workflow)
    {
        $data = $request->validate(['team_id' => ['nullable', 'integer', 'exists:response_teams,id']]);
        $workflow->review($schedule, $request->user(), isset($data['team_id']) ? 'confirmed' : 'acknowledged', $data['team_id'] ?? null);

        return back()->with('success', isset($data['team_id']) ? 'Schedule confirmed and team notified.' : 'Request acknowledged. Assign a response team next.');
    }

    public function decline(Request $request, ServiceSchedule $schedule, ServiceScheduleWorkflow $workflow)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $workflow->review($schedule, $request->user(), 'declined', reason: $data['reason']);

        return back()->with('success', 'Schedule declined.');
    }
}
