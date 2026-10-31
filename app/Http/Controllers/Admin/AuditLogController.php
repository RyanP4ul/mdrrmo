<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->only('search', 'action', 'from', 'to');

        return Inertia::render('Admin/AuditLogs', [
            'filters' => $f,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'logs' => AuditLog::query()
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                    ->where('user_name', 'like', "%{$s}%")->orWhere('details', 'like', "%{$s}%")))
                ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
                ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->latest('created_at')
                ->paginate(8)->withQueryString(),
        ]);
    }
}
