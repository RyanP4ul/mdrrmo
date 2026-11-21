<?php

namespace App\Http\Controllers;

use App\Events\EmergencyReportUpdated;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
            'incident_type_id' => ['required_without:incident_type_name', 'nullable', 'integer', 'exists:incident_types,id'],
            'incident_type_name' => ['required_without:incident_type_id', 'nullable', 'string', 'max:255'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'reporter_name' => ['required', 'string', 'max:121', 'regex:/^\S.{0,59} \S.{0,59}$/u'],
            'reporter_contact' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'photo' => [config('mobile.guest_photo_required') ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
        if (! empty($data['incident_type_id'])) {
            $type = IncidentType::query()->findOrFail($data['incident_type_id']);
        } else {
            $name = trim((string) $data['incident_type_name']);
            $type = IncidentType::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first()
                ?? IncidentType::query()->firstOrCreate(
                    ['name' => $name],
                    ['description' => $name, 'priority' => 'high', 'color' => '#6b7280'],
                );
            $data['incident_type_id'] = $type->id;
        }
        unset($data['incident_type_name']);
        unset($data['photo']);
        $data['description'] ??= '';
        $photoPath = $request->file('photo')?->store('incident-photos', 'r2');
        abort_if($photoPath === false, 503, 'The incident photo could not be saved. Please retry.');
        try {
            $report = DB::transaction(fn () => EmergencyReport::query()->create($data + [
                'reference' => EmergencyReport::generateReference(),
                'status' => 'pending',
                'priority' => $type->priority,
                'source' => 'mobile-app',
                'reported_by' => null,
                'reported_by_type' => null,
                'reported_at' => now(),
                'photo_path' => $photoPath,
            ]));
        } catch (Throwable $error) {
            if ($photoPath !== null) {
                Storage::disk('r2')->delete($photoPath);
            }
            throw $error;
        }

        try {
            EmergencyReportUpdated::dispatch($report, null, true);
        } catch (BroadcastException $error) {
            report($error);
        }

        return response()->json([
            'reference' => $report->reference,
            'message' => 'Your report has been received by MDRRMO. Keep this reference when contacting MDRRMO.',
        ], 201);
    }
}
