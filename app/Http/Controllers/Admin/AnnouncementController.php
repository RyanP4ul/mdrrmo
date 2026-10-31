<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only('search', 'category');

        return Inertia::render('Admin/Announcements/Index', [
            'filters'    => $filters,
            'categories' => Announcement::CATEGORIES,
            'announcements' => Announcement::with('author:id,first_name,last_name')
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
                ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
                ->latest()
                ->paginate(4)->withQueryString()
                ->through(fn ($a) => [
                    'id' => $a->id, 'title' => $a->title, 'category' => $a->category,
                    'description' => $a->description,
                    'image' => $a->image_path ? Storage::url($a->image_path) : null,
                    'postedAt' => $a->created_at->toIso8601String(),
                    'postedBy' => $a->author->full_name,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $a = Announcement::create($data + [
            'posted_by' => $request->user()->getKey(),
            'posted_by_type' => $request->user()->getMorphClass(),
        ]);
        Audit::record('CREATE_ANNOUNCEMENT', "Posted announcement \"{$a->title}\"");

        return back()->with('success', 'Announcement created.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $this->validated($request);
        if (isset($data['image_path']) && $announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->update($data);
        Audit::record('UPDATE_ANNOUNCEMENT', "Updated announcement \"{$announcement->title}\"");

        return back()->with('success', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement)
    {
        if ($announcement->image_path) Storage::disk('public')->delete($announcement->image_path);
        $announcement->delete();
        Audit::record('DELETE_ANNOUNCEMENT', "Deleted announcement \"{$announcement->title}\"");

        return back()->with('success', 'Announcement deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:150'],
            'category'    => ['required', Rule::in(Announcement::CATEGORIES)],
            'description' => ['required', 'string', 'max:5000'],
            'image'       => ['nullable', 'image', 'max:2048'],   // real upload instead of a pasted URL
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('announcements', 'public');
        }
        unset($data['image']);

        return $data;
    }
}
