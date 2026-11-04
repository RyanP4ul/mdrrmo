<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** Read-only announcements for dispatchers, drivers and residents. */
class AnnouncementFeedController extends Controller
{
    public function __invoke(Request $request)
    {
        $f = $request->only('search', 'category');

        return Inertia::render('Shared/Announcements', [
            'filters' => $f, 'categories' => Announcement::CATEGORIES,
            'announcements' => Announcement::with('author:id,first_name,last_name')
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%$s%"))
                ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
                ->latest()->paginate(4)->withQueryString()
                ->through(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'category' => $a->category, 'description' => $a->description,
                    'image' => $a->image_path ? Storage::url($a->image_path) : null,
                    'postedAt' => $a->created_at->toIso8601String(), 'postedBy' => $a->author?->full_name ?? 'Former user']),
        ]);
    }
}
