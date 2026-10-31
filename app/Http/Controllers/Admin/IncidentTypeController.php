<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncidentType;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IncidentTypeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only('search', 'priority');

        return Inertia::render('Admin/IncidentTypes/Index', [
            'filters' => $filters,
            'types' => IncidentType::query()
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
                ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
                ->withCount('reports')
                ->orderBy('name')
                ->paginate(6)->withQueryString(),
        ]);
    }

    public function store(Request $request)
    {
        $type = IncidentType::create($this->validated($request));
        Audit::record('CREATE_INCIDENT_TYPE', "Created incident type \"{$type->name}\"");

        return back()->with('success', 'Incident type created.');
    }

    public function update(Request $request, IncidentType $incidentType)
    {
        $incidentType->update($this->validated($request, $incidentType));
        Audit::record('UPDATE_INCIDENT_TYPE', "Updated incident type \"{$incidentType->name}\"");

        return back()->with('success', 'Incident type updated.');
    }

    public function destroy(IncidentType $incidentType)
    {
        if ($incidentType->reports()->exists()) {
            return back()->with('error', 'This type is used by existing reports and cannot be deleted.');
        }
        $incidentType->delete();
        Audit::record('DELETE_INCIDENT_TYPE', "Deleted incident type \"{$incidentType->name}\"");

        return back()->with('success', 'Incident type deleted.');
    }

    private function validated(Request $request, ?IncidentType $ignore = null): array
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:80', Rule::unique('incident_types', 'name')->ignore($ignore)],
            'description' => ['required', 'string', 'max:500'],
            'priority'    => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'color'       => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $data['color'] ??= '#6b7280';

        return $data;
    }
}
