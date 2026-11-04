<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\IncidentType;
use App\Support\Audit;
use App\Support\MobilePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileCatalogController extends Controller
{
    public function announcements(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', Rule::in(Announcement::CATEGORIES)]]);

        return response()->json(MobilePage::make(Announcement::with('author:id,first_name,last_name')->when($data['category'] ?? null, fn ($q, $category) => $q->where('category', $category)), fn ($a) => $this->announcementData($a), $request) + ['categories' => Announcement::CATEGORIES]);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return response()->json($this->announcementData($announcement));
    }

    private function announcementData(Announcement $announcement): array
    {
        $announcement->loadMissing('author');

        return $announcement->only(['id', 'title', 'category', 'description', 'created_at', 'updated_at']) + ['author' => $announcement->author?->full_name ?? 'Former user', 'has_image' => filled($announcement->image_path)];
    }

    public function image(Announcement $announcement): StreamedResponse
    {
        abort_unless($announcement->image_path && Storage::disk('public')->exists($announcement->image_path), 404);

        return Storage::disk('public')->response($announcement->image_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function saveAnnouncement(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:150'], 'category' => ['required', Rule::in(Announcement::CATEGORIES)], 'description' => ['required', 'string', 'max:5000'], 'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'], 'remove_image' => ['sometimes', 'boolean']]);

        return DB::transaction(function () use ($request, $id, $data): JsonResponse {
            $a = $id ? Announcement::whereKey($id)->lockForUpdate()->firstOrFail() : new Announcement;
            $old = $a->image_path;
            $a->fill(collect($data)->only(['title', 'category', 'description'])->all());
            if (! $id) {
                $a->forceFill(['posted_by' => $request->user()->id, 'posted_by_type' => $request->user()->getMorphClass()]);
            }
            if ($request->hasFile('image')) {
                $a->image_path = $request->file('image')->store('announcements', 'public');
            } elseif ($data['remove_image'] ?? false) {
                $a->image_path = null;
            }
            $a->save();
            if ($old && $old !== $a->image_path) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($old));
            }
            Audit::record($id ? 'UPDATE_ANNOUNCEMENT' : 'CREATE_ANNOUNCEMENT', 'Mobile announcement '.$a->title);

            return response()->json($this->announcementData($a), $id ? 200 : 201);
        });
    }

    public function deleteAnnouncement(Announcement $announcement): JsonResponse
    {
        DB::transaction(function () use ($announcement): void {
            $image = $announcement->image_path;
            $announcement->delete();
            if ($image) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($image));
            }
            Audit::record('DELETE_ANNOUNCEMENT', 'Deleted announcement '.$announcement->title);
        });

        return response()->json(['message' => 'Announcement deleted.']);
    }

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
