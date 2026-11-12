<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConnectivityTest extends TestCase
{
    public function test_public_reachability_check_does_not_require_a_database(): void
    {
        config(['database.default' => 'unavailable']);

        $this->getJson('/api/connectivity')->assertOk()
            ->assertExactJson(['service' => 'mdrrmo'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_optional_phone_lookup_failure_preserves_reachability(): void
    {
        config(['database.default' => 'unavailable']);

        $this->getJson('/api/connectivity?include_contact=1')->assertOk()
            ->assertExactJson(['service' => 'mdrrmo', 'dispatcher_phone' => null]);
    }
}
