<?php

namespace App\Http\Controllers;

use App\Events\VehicleLocationUpdated;
use App\Models\EmergencyReport;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class MobileLocationController extends Controller
{
    private function tokenId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'background' => ['required', 'boolean'],
            'sharing_mode' => ['sometimes', 'required', 'in:manual,automatic'],
            'report_id' => ['required_if:sharing_mode,automatic', 'integer'],
            'notice_version' => ['required_if:sharing_mode,automatic', 'in:2026-11-04'],
            'consent' => ['exclude_if:sharing_mode,automatic', 'required', 'accepted'],
            'consent_version' => ['exclude_if:sharing_mode,automatic', 'required', 'in:2026-11-03'],
        ]);
        $automatic = ($data['sharing_mode'] ?? 'manual') === 'automatic';

        return DB::transaction(function () use ($request, $data, $automatic): JsonResponse {
            $vehicle = Vehicle::whereKey($data['vehicle_id'])->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $vehicle);
            $report = EmergencyReport::whereKey($vehicle->assigned_report_id)->where('assigned_driver_id', $request->user()->id)->where('status', 'dispatched')->first();
            abort_unless($report, 422, 'Location sharing requires your active dispatched vehicle.');
            abort_if($automatic && $report->id !== (int) $data['report_id'], 409, 'The assigned dispatch has changed. Refresh your assignments.');
            DB::table('vehicle_tracking_sessions')->where('vehicle_id', $vehicle->id)->whereNull('stopped_at')->update(['stopped_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('vehicle_tracking_sessions')->insert([
                'id' => $id, 'vehicle_id' => $vehicle->id, 'driver_id' => $request->user()->id,
                'token_id' => $this->tokenId($request),
                'emergency_report_id' => $report->id, 'background' => $data['background'],
                // Legacy column also records automatic-sharing notice versions; this
                // is distinct from a manual consent checkbox on older clients.
                'consent_version' => $automatic ? 'automatic:'.$data['notice_version'] : $data['consent_version'], 'started_at' => now(), 'expires_at' => now()->addMinutes(3),
            ]);

            return response()->json(['session_id' => $id, 'expires_in' => 180], 201);
        });
    }

    public function ping(Request $request, string $session): JsonResponse
    {
        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'], 'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,200'], 'speed' => ['nullable', 'numeric', 'between:0,100'], 'heading' => ['nullable', 'numeric', 'between:0,360'],
            'observed_at' => ['required', 'date'],
        ]);
        $observed = Carbon::parse($data['observed_at']);
        abort_if($observed->lt(now()->subMinutes(2)) || $observed->gt(now()->addSeconds(30)), 422, 'The location reading is stale or in the future.');
        $vehicle = DB::transaction(function () use ($request, $session, $data, $observed): ?Vehicle {
            $tracking = DB::table('vehicle_tracking_sessions')->where('id', $session)->where('driver_id', $request->user()->id)
                ->where('token_id', $this->tokenId($request))->lockForUpdate()->first();
            abort_unless($tracking, 404);
            abort_if($tracking->stopped_at || Carbon::parse($tracking->expires_at)->isPast(), 409, 'Tracking stopped or expired. Location updates will reconnect automatically.');
            $vehicle = Vehicle::whereKey($tracking->vehicle_id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $vehicle);
            $active = EmergencyReport::whereKey($tracking->emergency_report_id)->where('status', 'dispatched')->where('assigned_driver_id', $request->user()->id)->exists();
            abort_unless($active && $vehicle->assigned_report_id === $tracking->emergency_report_id, 409, 'This dispatch is no longer active.');
            if ($data['sequence'] <= $tracking->last_sequence || ($tracking->last_observed_at && $observed->lte(Carbon::parse($tracking->last_observed_at)))) {
                return null;
            }
            DB::table('vehicle_tracking_sessions')->where('id', $session)->update(['last_sequence' => $data['sequence'], 'last_ping_at' => now(), 'last_observed_at' => $observed->utc(), 'expires_at' => now()->addMinutes(3)]);
            $vehicle->update(['lat' => $data['lat'], 'lng' => $data['lng'], 'speed' => (int) round(($data['speed'] ?? 0) * 3.6), 'heading' => (int) round($data['heading'] ?? 0), 'last_updated_at' => $observed->utc()]);

            return $vehicle;
        });
        if ($vehicle) {
            VehicleLocationUpdated::dispatch($vehicle);
        }

        return response()->json(['accepted' => $vehicle !== null]);
    }

    public function stop(Request $request, string $session): JsonResponse
    {
        $tracking = DB::table('vehicle_tracking_sessions')->where('id', $session)->where('driver_id', $request->user()->id)->first();
        abort_unless($tracking, 404);
        DB::table('vehicle_tracking_sessions')->where('id', $session)->whereNull('stopped_at')->update(['stopped_at' => now()]);

        return response()->json(['message' => 'Location sharing stopped.']);
    }
}
