<?php

namespace Tests\Unit;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_is_append_only_and_relations_preserve_author_context(): void
    {
        $organization = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $author = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::AK_OPERATOR]);
        $order = Order::factory()->create();
        $message = Message::query()->create([
            'public_token' => fake()->uuid(),
            'order_id' => $order->id,
            'author_user_id' => $author->id,
            'author_organization_id' => $organization->id,
            'body' => 'Neměnná zpráva',
            'idempotency_key' => 'unit-message-append-only',
            'request_hash' => hash('sha256', 'unit-message-append-only'),
        ]);

        self::assertTrue($message->order->is($order));
        self::assertTrue($message->author->is($author));
        self::assertTrue($message->authorOrganization->is($organization));
        try {
            $message->update(['body' => 'Přepsaná zpráva']);
            self::fail('A message must not be mutable.');
        } catch (\LogicException) {
            self::assertSame('Neměnná zpráva', $message->fresh()->body);
        }
        try {
            $message->delete();
            self::fail('A message must not be deletable.');
        } catch (\LogicException) {
            self::assertTrue($message->fresh()->exists);
        }
    }
}
