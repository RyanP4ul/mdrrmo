<?php

namespace App\Observers;

use App\Events\AdminNotificationCreated;
use App\Models\AdminNotification;
use App\Models\EmergencyReport;
use App\Models\ServiceSchedule;

class AdminNotificationObserver
{
    public function created(EmergencyReport|ServiceSchedule $record): void
    {
        $isIncident = $record instanceof EmergencyReport;
        $notification = AdminNotification::firstOrCreate([
            'kind' => $isIncident ? 'incident' : 'service_request',
            'subject_id' => $record->getKey(),
        ], [
            'title' => $isIncident ? 'New incident report: '.$record->reference : 'New service request: '.$record->service_type,
        ]);

        if ($notification->wasRecentlyCreated) {
            $notification->setRelation('incidentReport', $isIncident ? $record : null);
            AdminNotificationCreated::dispatch($notification);
        }
    }
}
