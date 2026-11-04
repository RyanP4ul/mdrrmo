<?php

namespace App\Observers;

use App\Models\EmergencyReport;
use App\Services\AutomaticDispatch;
use Illuminate\Support\Facades\DB;

class AutomaticDispatchObserver
{
    public function created(EmergencyReport $report): void
    {
        DB::afterCommit(function (): void {
            try {
                app(AutomaticDispatch::class)->drain();
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }
}
