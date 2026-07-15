<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_endpoint_returns_authenticated_user(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/auth/me');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_pengguna', $pengguna->id_pengguna);
    }
}
