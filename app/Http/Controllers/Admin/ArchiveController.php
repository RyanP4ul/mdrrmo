<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArchiveController extends Controller
{
    public function __construct(private ArchiveService $archives) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('type', 'state', 'search');

        $listing = $this->archives->list(
            [
                ...$filters,
                'page' => $request->query('page', 1),
            ],
            perPage: 10,
            path: $request->url(),
            query: $request->query(),
        );

        return Inertia::render('Admin/Archives/Index', [
            'filters' => [
                'type' => $filters['type'] ?? '',
                'state' => $filters['state'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'items' => $listing['items'],
            'counts' => $listing['counts'],
            'types' => collect(ArchiveService::TYPE_LABELS)
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values(),
        ]);
    }

    public function restore(string $type, string $key): RedirectResponse
    {
        $record = $this->archives->resolveArchivedRecord($type, $key);
        abort_unless($record !== null, 404);

        $this->archives->restore($record);

        return back()->with('success', 'Record restored from Archives.');
    }

    public function scheduleDelete(Request $request, string $type, string $key): RedirectResponse
    {
        $record = $this->archives->resolveArchivedRecord($type, $key);
        abort_unless($record !== null, 404);

        $this->archives->scheduleDeletion($record, $request->user());

        return back()->with('success', 'Deletion scheduled. Record will be permanently deleted in 30 days unless restored.');
    }

    public function cancelDelete(string $type, string $key): RedirectResponse
    {
        $record = $this->archives->resolveArchivedRecord($type, $key);
        abort_unless($record !== null, 404);

        $this->archives->cancelDeletion($record);

        return back()->with('success', 'Scheduled deletion cancelled. Record remains in Archives.');
    }
}
