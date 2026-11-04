<?php

namespace Tests\Feature;

use App\Actions\Auth\LoginAction;
use App\Auth\RoleUserProvider;
use App\Models\Account;
use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\ResidentUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_users_to_their_role_home(): void
    {
        foreach ([
            'admin' => 'admin.dashboard',
            'dispatcher' => 'dispatcher.dashboard',
            'driver/responder' => 'driver.dashboard',
            'resident' => 'resident.dashboard',
        ] as $role => $route) {
            $model = Account::modelForRole($role);
            $user = (new $model)->forceFill(['id' => 1, 'status' => 'active']);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertRedirect(route($route));
        }
    }

    public function test_users_cannot_open_another_roles_web_portal(): void
    {
        foreach ([
            ['resident', '/admin'],
            ['admin', '/dispatcher'],
            ['driver/responder', '/resident'],
            ['resident', '/driver'],
        ] as [$role, $path]) {
            $model = Account::modelForRole($role);
            $user = (new $model)->forceFill(['id' => 1, 'status' => 'active']);

            $this->actingAs($user)->get($path)->assertForbidden();
        }

        $inactiveDispatcher = (new DispatcherUser)->forceFill([
            'id' => 1,
            'status' => 'inactive',
        ]);
        $this->actingAs($inactiveDispatcher)->get('/dispatcher')->assertForbidden();
    }

    public function test_mobile_login_rejects_inactive_accounts_for_every_role(): void
    {
        foreach ([
            ['admin', 'inactive'],
            ['dispatcher', 'inactive'],
            ['resident', 'inactive'],
        ] as [$role, $status]) {
            $user = $this->createUser($role, $status);

            $this->postJson('/api/auth/mobile/login', [
                'email' => $user->email,
                'password' => 'password',
                'device_name' => 'test-device',
            ])->assertForbidden();
        }
    }

    public function test_mobile_login_issues_a_token_for_a_resident_account_table(): void
    {
        $resident = $this->createUser('resident');

        $this->postJson('/api/auth/mobile/login', [
            'email' => $resident->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ])->assertOk()->assertJsonPath('user.role', 'resident');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => ResidentUser::class,
            'tokenable_id' => $resident->getKey(),
            'name' => 'test-device',
        ]);
    }

    public function test_browser_login_sends_authenticated_users_to_role_dashboard(): void
    {
        $user = $this->createUser('resident');
        $user->forceFill(['password' => Hash::make('password')])->save();
        config(['session.driver' => 'database']);

        $this->post(route('api.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('sessions', [
            'user_id' => 'resident-'.$user->getKey(),
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('resident.dashboard'));
    }

    public function test_authenticated_users_can_log_out_of_their_browser_session(): void
    {
        $user = $this->createUser('resident');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_to_login_when_they_attempt_to_log_out(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_driver_api_is_limited_to_driver_responder_accounts(): void
    {
        $driver = $this->createUser('driver/responder');
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/teams')->assertOk();

        $resident = $this->createUser('resident');
        Sanctum::actingAs($resident, ['*']);
        $this->getJson('/api/driver-responder/teams')->assertForbidden();
    }

    public function test_browser_login_checks_that_the_account_is_active(): void
    {
        Auth::shouldReceive('attempt')
            ->once()
            ->with([
                'email' => 'user@example.test',
                'password' => 'password',
                'status' => 'active',
            ])
            ->andReturnFalse();

        $this->assertFalse(app(LoginAction::class)->execute('user@example.test', 'password'));
    }

    private function createUser(string $role, string $status = 'active'): User
    {
        $model = Account::modelForRole($role);

        return $model::forceCreate([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'status' => $status,
        ]);
    }

    public function test_four_account_roles_are_stored_in_separate_tables(): void
    {
        $accounts = [
            AdminUser::factory()->create(),
            DispatcherUser::factory()->create(),
            DriverResponderUser::factory()->create(),
            ResidentUser::factory()->create(),
        ];

        $this->assertDatabaseCount('admins', 1);
        $this->assertDatabaseCount('dispatchers', 1);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('residents', 1);
        $this->assertCount(4, array_unique(array_map(fn (User $user): int => (int) $user->getKey(), $accounts)));
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_auth_provider_can_retrieve_legacy_numeric_session_ids(): void
    {
        $resident = $this->createUser('resident');

        $this->assertSame(
            $resident->getKey(),
            app(RoleUserProvider::class)->retrieveById((string) $resident->getKey())?->getKey(),
        );
    }

    public function test_admin_user_management_creates_accounts_in_the_selected_table(): void
    {
        $admin = AdminUser::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'first_name' => 'New',
                'last_name' => 'Dispatcher',
                'email' => 'new.dispatcher@example.test',
                'contact_number' => '555-0101',
                'role' => 'dispatcher',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('dispatchers', [
            'email' => 'new.dispatcher@example.test',
            'first_name' => 'New',
        ]);
        $this->assertDatabaseMissing('admins', ['email' => 'new.dispatcher@example.test']);
    }

    public function test_admin_user_management_rejects_an_email_used_in_another_account_table(): void
    {
        $admin = AdminUser::factory()->create();
        DispatcherUser::factory()->create(['email' => 'duplicate@example.test']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'first_name' => 'New',
                'last_name' => 'Resident',
                'email' => 'duplicate@example.test',
                'contact_number' => '555-0102',
                'role' => 'resident',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('residents', ['email' => 'duplicate@example.test']);
    }

    public function test_account_role_cannot_be_changed_after_creation(): void
    {
        $admin = AdminUser::factory()->create();
        $dispatcher = DispatcherUser::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', Account::keyFor($dispatcher)), [
                'first_name' => $dispatcher->first_name,
                'last_name' => $dispatcher->last_name,
                'email' => $dispatcher->email,
                'contact_number' => '555-0199',
                'role' => 'driver/responder',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('dispatchers', ['id' => $dispatcher->getKey()]);
        $this->assertDatabaseMissing('drivers', ['email' => $dispatcher->email]);
    }
}
