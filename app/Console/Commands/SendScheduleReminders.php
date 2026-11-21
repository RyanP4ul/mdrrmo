<?php

namespace App\Console\Commands;

use App\Events\ServiceScheduleReminder;
use App\Models\DriverNotification;
use App\Models\ServiceSchedule;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Command;

class SendScheduleReminders extends Command
{
    protected $signature = 'mobile:send-schedule-reminders';

    protected $description = 'Send reminder notifications to assigned drivers for upcoming confirmed service schedules';

    public function handle(): int
    {
        $timezone = (string) config('mobile.timezone', 'Asia/Manila');
        $leadMinutes = max(1, (int) config('mobile.service_reminder_minutes', 30));
        $now = Carbon::now($timezone);
        $horizon = $now->copy()->addMinutes($leadMinutes);

        $schedules = ServiceSchedule::query()
            ->with(['responders:id', 'resident:id,first_name,last_name'])
            ->where('status', 'confirmed')
            ->whereDate('preferred_date', '>=', $now->copy()->subDay()->toDateString())
            ->whereDate('preferred_date', '<=', $horizon->toDateString())
            ->get();

        $sentCount = 0;

        foreach ($schedules as $schedule) {
            $scheduledAt = $schedule->scheduledAt();
            $diffMinutes = (int) $now->diffInMinutes($scheduledAt, false);

            // Remind once when within the lead window up to the scheduled start time (allow 5 min grace).
            if ($diffMinutes > $leadMinutes || $diffMinutes < -5) {
                continue;
            }

            $label = $schedule->service_details ?: $schedule->service_type;
            $residentName = $schedule->resident?->full_name ?? 'Resident';
            $timeText = Carbon::parse($schedule->preferred_time)->format('g:i A');
            $notifiedDriverIds = [];

            foreach ($schedule->responders as $driver) {
                $notification = DriverNotification::query()->firstOrCreate(
                    [
                        'driver_id' => $driver->getKey(),
                        'schedule_id' => $schedule->getKey(),
                        'kind' => 'schedule_reminder',
                    ],
                    [
                        'title' => 'Upcoming scheduled service',
                        'message' => "{$label} for {$residentName} is scheduled at {$timeText} ({$schedule->address}). Open the Service tab to start.",
                    ],
                );

                if ($notification->wasRecentlyCreated) {
                    $notifiedDriverIds[] = (int) $driver->getKey();
                    $sentCount++;
                }
            }

            if ($notifiedDriverIds !== []) {
                try {
                    ServiceScheduleReminder::dispatch($schedule, $notifiedDriverIds, max(0, $diffMinutes));
                } catch (BroadcastException $exception) {
                    report($exception);
                }
            }
        }

        $this->info("Sent {$sentCount} schedule reminder(s).");

        return self::SUCCESS;
    }
}
