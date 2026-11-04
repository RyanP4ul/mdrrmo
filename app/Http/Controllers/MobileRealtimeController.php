<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class MobileRealtimeController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $app = config('reverb.apps.apps.0');

        return response()->json([
            'enabled' => config('broadcasting.default') === 'reverb' && filled($app['key'] ?? null),
            'key' => $app['key'] ?? '',
            'host' => config('mobile.reverb_public_host') ?: $request->getHost(),
            'port' => (int) ($app['options']['port'] ?? 8080),
            'secure' => ($app['options']['scheme'] ?? 'https') === 'https',
            'channel' => 'private-drivers.'.$request->user()->id,
        ]);
    }

    public function authenticate(Request $request): mixed
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'max:64', 'regex:/^\\d+\\.\\d+$/'], 'channel_name' => ['required', 'string', 'max:100']]);
        abort_unless($data['channel_name'] === 'private-drivers.'.$request->user()->id, 403);

        return Broadcast::connection('reverb')->auth($request);
    }

    public function fleet(Request $request): JsonResponse
    {
        $snapshotAt = now()->toISOString();
        $vehicles = Vehicle::with('team:id,team_name')->whereHas('team', fn ($q) => $q->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active')))->orderBy('id')->get();

        return response()->json(['data' => $vehicles->map(fn ($v) => [
            'id' => $v->id, 'lat' => $v->lat, 'lng' => $v->lng, 'plate' => $v->plate_number, 'type' => $v->vehicle_type,
            'teamName' => $v->team?->team_name, 'status' => $v->status, 'speed' => $v->speed, 'heading' => $v->heading,
            'assignedReportId' => $v->assigned_report_id, 'updatedAt' => $v->last_updated_at?->toISOString(),
        ]), 'snapshotAt' => $snapshotAt]);
    }
}
