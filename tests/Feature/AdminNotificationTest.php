<?php

namespace Tests\Feature;

use App\Events\AdminNotificationCreated;
use App\Models\AdminUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ServiceSchedule;
use App\Observers\AdminNotificationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_incidents_and_service_requests_create_persistent_admin_alerts(): void
    {
        Event::fake([AdminNotificationCreated::class]);
        $resident = ResidentUser::factory()->accepted()->create();
        $admin = AdminUser::factory()->create();
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical incident', 'priority' => 'high']);

        $this->actingAs($resident)->post(route('resident.report.store'), [
            'incident_type_id' => $type->id, 'reporter_type' => 'victim', 'location' => 'Town hall',
            'lat' => 14.8, 'lng' => 120.9, 'description' => 'Ambulance requested.',
        ])->assertRedirect(route('resident.history'));
        $report = EmergencyReport::firstOrFail();

        ServiceSchedule::create([
            'resident_id' => $resident->id, 'service_type' => 'Medical', 'preferred_date' => now()->addDay(),
            'preferred_time' => '09:00', 'address' => 'Town hall', 'status' => 'pending',
        ]);
        app(AdminNotificationObserver::class)->created($report);
        $report->update(['priority' => 'critical']);

        Event::assertDispatchedTimes(AdminNotificationCreated::class, 2);
        Event::assertDispatched(AdminNotificationCreated::class, fn (AdminNotificationCreated $event) =>
            (string) $event->broadcastOn() === 'private-admin.notifications'
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith()['url'] === route('admin.reports.show', $report, false));

        $this->getJson(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('admin.notifications.index'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'New service request: Medical')
            ->assertJsonPath('data.0.url', null)
            ->assertJsonPath('data.1.title', 'New incident report: '.$report->reference)
            ->assertJsonPath('data.1.url', route('admin.reports.show', $report, false))
            ->assertJsonPath('next_cursor', null);
        $this->getJson(route('admin.notifications.index'))->assertOk()->assertJsonCount(2, 'data');
    }
}
