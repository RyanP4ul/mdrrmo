<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\{Announcement, EmergencyReport, IncidentType, ResidentNotification, ServiceSchedule};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ResidentController extends Controller
    {
    public const SERVICES = ['Ambulance Standby', 'Medical Mission', 'Fire Safety Inspection', 'Disaster Drill', 'First Aid Training', 'Other'];

    public function dashboard(Request $request)
    {
        $u = $request->user();
        return Inertia::render('Resident/Dashboard', [
            'active' => $u->reports()->with('incidentType:id,name,color', 'team:id,team_name')->active()->latest('reported_at')->get(),
            'announcements' => Announcement::latest()->limit(3)->get(['id', 'title', 'category', 'description', 'created_at']),
            'unread' => ResidentNotification::where('user_id', $u->id)->whereNull('read_at')->count(),
            'approved' => $u->registration_status === 'accepted',
        ]);
}

    public function createReport()
    {
        return Inertia::render('Resident/Report', ['types' => IncidentType::orderBy('name')->get(['id', 'name', 'description'])]);
}

    public function storeReport(Request $request)
    {
        abort_unless($request->user()->registration_status === 'accepted', 403, 'Your account is awaiting approval.');
        $d = $request->validate([
            'incident_type_id' => ['required', 'exists:incident_types,id'], 'reporter_type' => ['required', 'in:victim,witness'],
            'location' => ['required', 'string', 'max:255'], 'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['required', 'string', 'max:2000'],
        ]);
        $u = $request->user();

        EmergencyReport::create($d + [
            'reference' => EmergencyReport::generateReference(),
            'priority' => IncidentType::find($d['incident_type_id'])->priority, 'source' => 'mobile-app',
            'reported_by' => $u->getKey(), 'reported_by_type' => $u->getMorphClass(),
            'reporter_name' => $u->full_name, 'reporter_contact' => $u->contact_number,
        ]);

        return redirect()->route('resident.history')->with('success', 'Report sent. Help is being arranged.');
}

    public function history(Request $request)
    {
        return Inertia::render('Resident/History', [
            'reports' => $request->user()->reports()->with('incidentType:id,name,color', 'team:id,team_name')->latest('reported_at')->paginate(6)->withQueryString(),
        ]);
}

    public function schedule(Request $request)
    {
        return Inertia::render('Resident/Schedule', [
            'services' => self::SERVICES,
            'schedules' => $request->user()->schedules()->latest()->paginate(6)->withQueryString(),
        ]);
}

    public function storeSchedule(Request $request)
    {
        abort_unless($request->user()->registration_status === 'accepted', 403, 'Your account is awaiting approval.');

        $d = $request->validate([
            'service_type' => ['required', 'in:' . implode(',', self::SERVICES)],
            'preferred_date' => ['required', 'date', 'after_or_equal:today'], 'preferred_time' => ['required', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $request->user()->schedules()->create($d);

        return back()->with('success', 'Request submitted. You will be notified once it is reviewed.');
}

    public function cancelSchedule(Request $request, ServiceSchedule $schedule)
    {
        Gate::authorize('cancel', $schedule);
        $schedule->update(['status' => 'cancelled']);
        return back()->with('success', 'Request cancelled.');
}

    public function notifications(Request $request)
    {
        return Inertia::render('Resident/Notifications', [
            'items' => ResidentNotification::where('user_id', $request->user()->id)->latest()->paginate(10)->withQueryString(),
        ]);
}

    public function markRead(Request $request, ?ResidentNotification $notification = null)
    {
        $q = ResidentNotification::where('user_id', $request->user()->id)->whereNull('read_at');
        ($notification ? $q->whereKey($notification->id) : $q)->update(['read_at' => now()]);
        return back();
}
}
