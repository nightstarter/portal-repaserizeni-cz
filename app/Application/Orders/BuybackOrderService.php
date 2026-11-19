<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Enums\BuybackAction;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\ItemRole;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Exceptions\WorkflowConflictException;
use App\Models\BuybackPriceQuote;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class BuybackOrderService
{
    public function __construct(
        private readonly BuybackActionGuard $guard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(CreateBuybackCommand $command): Order
    {
        $this->guard->assertMayPerform($command->actor, BuybackAction::CREATE);
        Validator::make(['key' => $command->idempotencyKey], ['key' => ['required', 'string', 'max:128']])->validate();
        $data = Validator::make($command->data, [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'product_code' => ['required', 'string', 'max:64'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'technical_type' => ['nullable', 'string', 'max:32'],
            'routing' => ['nullable', 'string', 'max:16'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'internal_offer_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000000'],
            'internal_offer_currency' => ['nullable', 'required_with:internal_offer_minor', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($command, $data, $hash): Order {
            $existing = Order::query()->where('creation_idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->creation_request_hash !== $hash) {
                    throw new WorkflowConflictException('Idempotency key was reused with another buyback request.');
                }

                return $existing;
            }

            $year = (int) now()->format('Y');
            DB::table('buyback_order_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('buyback_order_sequences')->where('year', $year)->lockForUpdate()->first();
            $number = ((int) $sequence->last_number) + 1;
            if ($number > 99999) {
                throw new \RuntimeException('The annual buyback order-number sequence is exhausted.');
            }
            DB::table('buyback_order_sequences')->where('year', $year)->update(['last_number' => $number, 'updated_at' => now()]);
            $autokluby = Organization::query()->where('code', OrganizationCode::AUTOKLUBY)->sole();
            $order = Order::query()->create([
                'order_no' => sprintf('VYK-%d-%05d', $year, $number),
                'source' => OrderSource::SYSTEM_BUYOUT,
                'source_order_id' => null,
                'creation_idempotency_key' => $command->idempotencyKey,
                'creation_request_hash' => $hash,
                'order_type' => OrderType::BUYOUT,
                'technical_type' => $data['technical_type'] ?? null,
                'routing' => $data['routing'] ?? null,
                'product_code' => $data['product_code'],
                'product_name_snapshot' => $data['product_name'] ?? null,
            ]);
            $order->customer()->create([
                'name' => $data['customer_name'],
                'email' => $data['customer_email'] ?? null,
                'phone' => $data['customer_phone'] ?? null,
            ]);
            $item = $order->items()->create([
                'role' => ItemRole::BUYOUT_ITEM,
                'role_instance' => 1,
                'qr_token' => Str::random(48),
                'current_location' => 'CUSTOMER',
                'note' => $data['internal_note'] ?? null,
            ]);
            $thread = $order->processThreads()->create([
                'item_id' => $item->id,
                'type' => ProcessThreadType::BUYOUT,
                'type_instance' => 1,
                'state' => BuybackState::DRAFT->value,
                'responsible_party' => ResponsibleParty::AUTOKLUBY,
                'responsible_organization_id' => $autokluby->id,
                'created_by' => $command->actor->id,
                'creation_idempotency_key' => 'buyback-thread:'.$command->idempotencyKey,
                'creation_request_hash' => $hash,
            ]);
            $event = $thread->events()->create([
                'order_id' => $order->id,
                'action' => BuybackAction::CREATE->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $hash,
                'to_state' => BuybackState::DRAFT->value,
                'actor_type' => OrganizationCode::AUTOKLUBY->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $autokluby->id,
                'metadata' => ['item_id' => $item->id],
            ]);
            if (array_key_exists('internal_offer_minor', $data)) {
                $quote = BuybackPriceQuote::query()->create([
                    'order_id' => $order->id, 'process_thread_id' => $thread->id,
                    'type' => BuybackPriceType::INTERNAL_AK, 'amount_minor' => $data['internal_offer_minor'],
                    'currency' => $data['internal_offer_currency'], 'version' => 1,
                    'actor_user_id' => $command->actor->id, 'actor_organization_id' => $command->actor->organization_id,
                    'idempotency_key' => 'create-price:'.$command->idempotencyKey, 'request_hash' => $hash,
                ]);
                $thread->events()->create([
                    'order_id' => $order->id, 'action' => BuybackAction::SET_INTERNAL_PRICE->value,
                    'idempotency_key' => 'create-price:'.$command->idempotencyKey, 'request_hash' => $hash,
                    'from_state' => BuybackState::DRAFT->value, 'to_state' => BuybackState::DRAFT->value,
                    'actor_type' => OrganizationCode::AUTOKLUBY->value, 'actor_user_id' => $command->actor->id,
                    'actor_organization_id' => $command->actor->organization_id,
                    'responsible_organization_id' => $autokluby->id, 'metadata' => ['price_quote_id' => $quote->id, 'item_id' => $item->id],
                ]);
                $thread->increment('lock_version');
            }
            $this->auditLogger->record('BUYBACK_CREATED', $order, [], [
                'order_no' => $order->order_no, 'thread_id' => $thread->id, 'thread_event_id' => $event->id,
            ], null, $command->actor);

            return $order;
        }, 3);
    }
}
