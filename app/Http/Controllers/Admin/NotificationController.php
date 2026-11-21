<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $page = AdminNotification::with('incidentReport:id')->latest('created_at')->orderByDesc('id')->cursorPaginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (AdminNotification $notification) => $notification->payload()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
