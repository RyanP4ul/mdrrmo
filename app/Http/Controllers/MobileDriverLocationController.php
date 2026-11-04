<?php

namespace App\Http\Controllers;

use App\Events\DriverLocationUpdated;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\TeamMember;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDriverLocationController extends Controller
{
    private function tokenId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }

    public function index(Request $request): JsonResponse
    {
        $snapshotAt = now()->toISOString();
        $teams = $request->user()->activeMemberships()->pluck('response_team_id');
        $ids = TeamMember::whereIn('response_team_id', $teams)->where('status', 'active')->pluck('user_id')->push($request->user()->id)->unique();
        $locations = DriverLocation::with('driver.activeAssignments.trip', 'driver.activeAssignments.team', 'driver.activeMemberships.team')
            ->whereIn('driver_id', $ids)->whereHas('driver', fn ($query) => $query->where('status', 'active'))->orderBy('driver_id')->get();

        return response()->json(['data' => $locations->map(fn (DriverLocation $location): array => $location->mapData($snapshotAt)), 'snapshotAt' => $snapshotAt]);
    }

    public function staffIndex(): JsonResponse
    {
        $snapshotAt = now()->toISOString();
        $locations = DriverLocation::with('driver.activeAssignments.trip', 'driver.activeAssignments.team', 'driver.activeMemberships.team')
            ->whereHas('driver', fn ($query) => $query->where('status', 'active'))->orderBy('driver_id')->get();

        return response()->json(['data' => $locations->map(fn (DriverLocation $location): array => $location->mapData($snapshotAt)), 'snapshotAt' => $snapshotAt]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['background' => ['required', 'boolean'], 'notice_version' => ['required', 'in:driver-2026-11-04']]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            DriverResponderUser::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('driver_location_sessions')->where('driver_id', $request->user()->id)->whereNull('stopped_at')->update(['stopped_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('driver_location_sessions')->insert([
                'id' => $id, 'driver_id' => $request->user()->id, 'token_id' => $this->tokenId($request),
                'background' => $data['background'], 'notice_version' => $data['notice_version'],
                'started_at' => now(), 'expires_at' => now()->addMinutes(3),
            ]);

            return response()->json(['session_id' => $id, 'expires_in' => 180], 201);
        });
    }

    public function ping(Request $request, string $session): JsonResponse
    {
        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'], 'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'], 'accuracy' => ['required', 'numeric', 'between:0,200'],
            'speed' => ['nullable', 'numeric', 'between:0,100'], 'heading' => ['nullable', 'numeric', 'between:0,360'], 'observed_at' => ['required', 'date'],
        ]);
        $observed = Carbon::parse($data['observed_at']);
        abort_if($observed->lt(now()->subMinutes(2)) || $observed->gt(now()->addSeconds(30)), 422, 'The GPS reading is stale or in the future.');
        $location = DB::transaction(function () use ($request, $session, $data, $observed): ?DriverLocation {
            DriverResponderUser::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $tracking = DB::table('driver_location_sessions')->where('id', $session)->where('driver_id', $request->user()->id)
                ->where('token_id', $this->tokenId($request))->lockForUpdate()->first();
            abort_unless($tracking, 404);
            abort_if($tracking->stopped_at || Carbon::parse($tracking->expires_at)->isPast(), 409, 'Location session stopped or expired. Reconnecting automatically.');
            $previous = DriverLocation::find($request->user()->id);
            if ($data['sequence'] <= $tracking->last_sequence || ($tracking->last_observed_at && $observed->lte(Carbon::parse($tracking->last_observed_at))) || ($previous && $observed->lt($previous->observed_at))) {
                return null;
            }
            DB::table('driver_location_sessions')->where('id', $session)->update(['last_sequence' => $data['sequence'], 'last_observed_at' => $observed->utc(), 'expires_at' => now()->addMinutes(3)]);

            return DriverLocation::updateOrCreate(['driver_id' => $request->user()->id], [
                'session_id' => $session, 'lat' => $data['lat'], 'lng' => $data['lng'], 'accuracy' => $data['accuracy'],
                'speed' => (int) round(($data['speed'] ?? 0) * 3.6), 'heading' => (int) round($data['heading'] ?? 0),
                'observed_at' => $observed->utc(), 'expires_at' => now()->addMinutes(3),
            ]);
        });
        if ($location) {
            DriverLocationUpdated::dispatch($location);
        }

        return response()->json(['accepted' => $location !== null]);
    }

    public function stop(Request $request, string $session): JsonResponse
    {
        $location = DB::transaction(function () use ($request, $session): ?DriverLocation {
            DriverResponderUser::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $tracking = DB::table('driver_location_sessions')->where('id', $session)->where('driver_id', $request->user()->id)->where('token_id', $this->tokenId($request))->first();
            abort_unless($tracking, 404);
            DB::table('driver_location_sessions')->where('id', $session)->whereNull('stopped_at')->update(['stopped_at' => now()]);
            $location = DriverLocation::where('driver_id', $request->user()->id)->where('session_id', $session)->first();
            if ($location) {
                $location->update(['expires_at' => now()->subSecond()]);
            }

            return $location;
        });
        if ($location) {
            DriverLocationUpdated::dispatch($location);
        }

        return response()->json(['message' => 'Location updates stopped.']);
    }
}
