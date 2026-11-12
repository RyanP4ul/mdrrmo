<?php

namespace Tests\Feature;

use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResidentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_inboxes_counts_and_read_actions_are_isolated_between_accounts(): void
    {
        $first = ResidentUser::factory()->accepted()->create();
        $second = ResidentUser::factory()->accepted()->create();
        $firstUpdate = ResidentNotification::create([
            'user_id' => $first->id, 'type' => 'report_update',
            'title' => 'Help is on the way', 'message' => 'A team has been assigned to your report.',
        ]);
        $secondUpdate = ResidentNotification::create([
            'user_id' => $second->id, 'type' => 'report_update',
            'title' => 'Report received', 'message' => 'Second resident report received.',
        ]);

        Sanctum::actingAs($first, ['*']);
        $this->getJson('/api/resident/bootstrap')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $firstUpdate->id);
        // A client-supplied recipient must never override the authenticated user.
        $this->getJson('/api/resident/notifications?user_id='.$second->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $firstUpdate->id);
        $this->patchJson('/api/resident/notifications/'.$secondUpdate->id.'/read')->assertNotFound();
        $this->patchJson('/api/resident/notifications/read')->assertOk();
        $this->assertNotNull($firstUpdate->fresh()->read_at);
        $this->assertNull($secondUpdate->fresh()->read_at);

        Sanctum::actingAs($second, ['*']);
        $this->getJson('/api/resident/bootstrap')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $secondUpdate->id);
        $this->getJson('/api/resident/notifications?search=assigned')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/resident/notifications?before='.$secondUpdate->id)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/resident/notifications/'.$secondUpdate->id.'/read')->assertOk();
        $this->getJson('/api/resident/bootstrap')->assertOk()->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notifications.0.read_at', fn ($value) => $value !== null);

        Sanctum::actingAs($first, ['*']);
        $this->getJson('/api/resident/bootstrap')->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $firstUpdate->id)
            ->assertJsonPath('notifications.0.read_at', fn ($value) => $value !== null);
    }
}
