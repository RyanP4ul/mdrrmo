<?php

namespace App\Support;

use App\Models\AuditLog;

class Audit
{
    /** Audit::record('CREATE_USER', 'Created user #5'); */
    public static function record(string $action, string $details): void
    {
        $user = auth()->user();

        AuditLog::create([
            'user_id'   => $user?->getKey(),
            'user_type' => $user?->getMorphClass(),
            'user_name' => $user?->full_name ?? 'System',
            'action'    => $action,
            'details'   => $details,
            'local_id'  => request()->ip(),
        ]);
    }
}
