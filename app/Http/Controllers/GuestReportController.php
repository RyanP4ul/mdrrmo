<?php

namespace App\Http\Controllers;

use App\Models\EmergencyReport;
use App\Models\IncidentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestReportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Guest/Report');
    }

    public function types(): JsonResponse
    {
        return response()->json([
            'incident_types' => IncidentType::query()->orderBy('name')->get(['id', 'name', 'description', 'color']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('reporter_name'))) {
            $request->merge(['reporter_name' => preg_replace('/\s+/u', ' ', trim($request->input('reporter_name')))]);
        }
        if (is_string($request->input('reporter_contact'))) {
            $request->merge(['reporter_contact' => preg_replace('/[\s-]+/', '', $request->input('reporter_contact'))]);
        }
        $data = $request->validate([
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'reporter_name' => ['required', 'string', 'max:121', 'regex:/^\S.{0,59} \S.{0,59}$/u'],
            'reporter_contact' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['required', 'string', 'max:2000'],
        ]);
        $type = IncidentType::query()->findOrFail($data['incident_type_id']);
        $report = EmergencyReport::query()->create($data + [
            'reference' => EmergencyReport::generateReference(),
            'status' => 'pending',
            'priority' => $type->priority,
            'source' => 'mobile-app',
            'reported_by' => null,
            'reported_by_type' => null,
            'reported_at' => now(),
        ]);

        return response()->json([
            'reference' => $report->reference,
            'message' => 'Your report has been received by MDRRMO. Keep this reference when contacting MDRRMO.',
        ], 201);
    }
}
