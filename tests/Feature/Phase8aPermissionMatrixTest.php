<?php

namespace Tests\Feature;

use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase8aPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_matrix_contains_required_roles_capabilities_and_invariants(): void
    {
        $matrix = config('permission_matrix');
        $requiredCapabilities = [
            'dashboard', 'order_view', 'customer_pii', 'customer_prices', 'documents',
            'discussion', 'shipments', 'core_return', 'warranty', 'buyback',
            'manage_users', 'manage_notifications', 'pohoda_diagnostics',
            'production_readiness', 'admin_override', 'domain_write',
        ];

        foreach ([UserRole::AK_ADMIN, UserRole::AK_OPERATOR, UserRole::PL_OPERATOR, UserRole::READ_ONLY] as $role) {
            $this->assertSame($requiredCapabilities, array_keys($matrix['roles'][$role->value]));
        }
        $this->assertTrue($matrix['roles'][UserRole::AK_ADMIN->value]['admin_override']);
        $this->assertFalse($matrix['roles'][UserRole::AK_OPERATOR->value]['manage_users']);
        $this->assertFalse($matrix['roles'][UserRole::PL_OPERATOR->value]['customer_pii']);
        $this->assertFalse($matrix['roles'][UserRole::PL_OPERATOR->value]['customer_prices']);
        $this->assertFalse($matrix['roles'][UserRole::READ_ONLY->value]['domain_write']);
        $this->assertTrue($matrix['rules']['row_scope_always_required']);
        $this->assertTrue($matrix['rules']['foreign_resource_returns_404']);
    }

    public function test_admin_endpoints_return_safe_404_to_operator_poland_and_read_only(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        $users = [
            User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_OPERATOR]),
            User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::READ_ONLY]),
            User::factory()->create(['organization_id' => $pl->id, 'role' => UserRole::PL_OPERATOR]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('admin.integrations.pohoda'))->assertNotFound();
            $this->actingAs($user)->get(route('admin.operations.preflight'))->assertNotFound();
        }

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('users.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.notifications.index'))->assertForbidden();
        }
    }

    public function test_read_only_discussion_get_and_post_never_write_unread_or_message_state(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $author = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_OPERATOR]);
        $readOnly = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::READ_ONLY]);
        $order = Order::factory()->create();
        ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $ak->id,
        ]);
        Message::query()->create([
            'order_id' => $order->id,
            'public_token' => (string) Str::uuid(),
            'author_user_id' => $author->id,
            'author_organization_id' => $ak->id,
            'body' => 'Bezpečná zpráva',
            'idempotency_key' => 'phase8a-read-only-source',
            'request_hash' => hash('sha256', 'phase8a-read-only-source'),
        ]);

        $this->actingAs($readOnly)->get(route('orders.discussion.index', $order))->assertOk();
        $this->assertSame(0, MessageRead::query()->count());
        $this->actingAs($readOnly)->post(route('orders.discussion.read', $order))->assertForbidden();
        $this->actingAs($readOnly)->post(route('orders.discussion.store', $order), [
            'body' => 'Nesmí vzniknout', 'idempotency_key' => 'phase8a-read-only-write',
        ])->assertForbidden();
        $this->assertSame(1, Message::query()->count());
        $this->assertSame(0, MessageRead::query()->count());
    }

    public function test_poland_foreign_order_direct_url_and_post_return_safe_404(): void
    {
        $pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        $operator = User::factory()->create(['organization_id' => $pl->id, 'role' => UserRole::PL_OPERATOR]);
        $foreignOrder = Order::factory()->create();
        ProcessThread::factory()->create([
            'order_id' => $foreignOrder->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => null,
        ]);

        $this->actingAs($operator)->get(route('orders.show', $foreignOrder))->assertNotFound();
        $this->actingAs($operator)->post(route('orders.discussion.store', $foreignOrder), [
            'body' => 'probe', 'idempotency_key' => 'phase8a-foreign-probe',
        ])->assertNotFound();
        $this->assertSame(0, Message::query()->count());
    }
}
