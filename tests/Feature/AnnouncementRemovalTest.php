<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\ResidentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_remove_announcement_storage_and_resident_home_does_not_query_it(): void
    {
        $this->assertFalse(Schema::hasTable('announcements'));
        $this->actingAs(ResidentUser::factory()->accepted()->create())
            ->get(route('resident.dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Resident/Dashboard')->missing('announcements'));
    }

    public function test_removed_website_and_mobile_announcement_endpoints_return_404(): void
    {
        $this->actingAs(AdminUser::factory()->create());
        $this->get('/admin/announcements')->assertNotFound();
        $this->post('/admin/announcements', [])->assertNotFound();
        $this->put('/admin/announcements/1', [])->assertNotFound();
        $this->delete('/admin/announcements/1')->assertNotFound();
        $this->actingAs(DispatcherUser::factory()->create())->get('/dispatcher/announcements')->assertNotFound();
        $resident = ResidentUser::factory()->accepted()->create();
        $this->actingAs($resident)->get('/resident/announcements')->assertNotFound();
        Sanctum::actingAs($resident, ['*']);
        $this->getJson('/api/mobile/announcements')->assertNotFound();
        $this->getJson('/api/mobile/announcements/1')->assertNotFound();
        $this->getJson('/api/mobile/announcements/1/image')->assertNotFound();
        Sanctum::actingAs(AdminUser::factory()->create(), ['*']);
        $this->postJson('/api/operations/announcements', [])->assertNotFound();
        $this->putJson('/api/operations/announcements/1', [])->assertNotFound();
        $this->deleteJson('/api/operations/announcements/1')->assertNotFound();
    }
}
