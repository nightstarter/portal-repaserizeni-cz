<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleAndHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public_and_contains_no_sensitive_details(): void
    {
        $this->get('/up')->assertOk()->assertDontSee('DB_PASSWORD');
    }

    public function test_locale_preference_is_persisted_and_audited(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->put('/locale', ['locale' => 'pl'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'preferred_locale' => 'pl']);
        $this->assertDatabaseHas('audit_log', ['actor_user_id' => $user->id, 'action' => 'USER_LOCALE_CHANGED']);
    }

    public function test_legacy_import_is_disabled_in_test_environment(): void
    {
        self::assertFalse(config('integrations.legacy_shop.enabled'));
    }
}
