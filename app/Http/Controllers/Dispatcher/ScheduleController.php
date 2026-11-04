<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\{ResidentNotification, ServiceSchedule};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ScheduleController extends Controller
    {
    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'acknowledged', 'confirmed', 'declined', 'completed', 'cancelled'])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $f['month'] ?? now()->format('Y-m'));

        return Inertia::render('Dispatcher/Schedule', [
            'filters' => $f, 'month' => $month->format('Y-m'),
            'schedules' => ServiceSchedule::with('resident:id,first_name,last_name,contact_number')
                ->whereBetween('preferred_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('preferred_date')->orderBy('preferred_time')->get()
                ->map(fn ($s) => $s->only('id', 'service_type', 'preferred_time', 'address', 'notes', 'status', 'decline_reason') + [
                    'date' => $s->preferred_date->toDateString(), 'resident' => $s->resident->full_name, 'contact' => $s->resident->contact_number]),
        ]);
}

    public function acknowledge(ServiceSchedule $schedule)
    {
        $schedule->update([
            'status' => 'confirmed',
            'acknowledged_by' => auth()->user()->getKey(),
            'acknowledged_by_type' => auth()->user()->getMorphClass(),
            'acknowledged_at' => now(),
        ]);
        $this->notify($schedule, 'schedule_confirmed', 'Schedule confirmed', "Your {$schedule->service_type} on {$schedule->preferred_date->toFormattedDateString()} is confirmed.");
        return back()->with('success', 'Schedule confirmed.');
}

    public function decline(Request $request, ServiceSchedule $schedule)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $schedule->update([
            'status' => 'declined',
            'decline_reason' => $reason,
            'acknowledged_by' => auth()->user()->getKey(),
            'acknowledged_by_type' => auth()->user()->getMorphClass(),
            'acknowledged_at' => now(),
        ]);
        $this->notify($schedule, 'schedule_cancelled', 'Schedule declined', "Reason: {$reason}");
        return back()->with('success', 'Schedule declined.');
}

    private function notify(ServiceSchedule $s, string $type, string $title, string $msg): void
    {
        ResidentNotification::create(['user_id' => $s->resident_id, 'type' => $type, 'title' => $title, 'message' => $msg,
            'related_type' => ServiceSchedule::class, 'related_id' => $s->id]);
}
}
