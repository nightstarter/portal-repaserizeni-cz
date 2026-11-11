<?php

namespace Tests\Feature;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_see_internal_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_active_user_can_log_in_and_login_is_audited(): void
    {
        $organization = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'email' => 'admin@example.test']);

        $this->post('/login', ['email' => $user->email, 'password' => 'TestPassword!123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', ['actor_user_id' => $user->id, 'action' => 'AUTH_LOGIN']);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id, 'active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'TestPassword!123']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_poland_operator_cannot_open_user_administration(): void
    {
        $organization = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $user = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::PL_OPERATOR]);

        $this->actingAs($user)->get('/users')->assertForbidden();
    }

    public function test_ak_admin_can_open_user_administration(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::AK_ADMIN]);

        $this->actingAs($user)->get('/users')->assertOk();
    }
}
