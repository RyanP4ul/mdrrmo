<?php

namespace App\Http\Controllers;

use App\Models\IncidentType;
use App\Support\Audit;
use App\Support\MobilePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileCatalogController extends Controller
{
    public function types(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(IncidentType::query(), request: $request));
    }

    public function saveType(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('incident_types', 'name')->ignore($id)], 'description' => ['required', 'string', 'max:500'], 'priority' => ['required', 'in:low,medium,high,critical'], 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $type = $id ? IncidentType::findOrFail($id) : new IncidentType;
        $type->fill($data)->save();
        Audit::record($id ? 'UPDATE_INCIDENT_TYPE' : 'CREATE_INCIDENT_TYPE', 'Mobile incident type '.$type->name);

        return response()->json($type, $id ? 200 : 201);
    }

    public function deleteType(IncidentType $type): JsonResponse
    {
        DB::transaction(function () use ($type): void {
            $type = IncidentType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            abort_if($type->reports()->exists(), 422, 'This incident type is used by existing reports.');
            $type->delete();
            Audit::record('DELETE_INCIDENT_TYPE', 'Deleted incident type '.$type->name);
        });

        return response()->json(['message' => 'Incident type deleted.']);
    }
}
