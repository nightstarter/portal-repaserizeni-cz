<section class="action-card branch-actions">
    <h3>{{ __('app.current_actions') }}</h3>
    @forelse($workflow['allowed_actions'] as $action)
        @php($formIdentity = $workflow['thread']->id.'-'.$action->value)
        @php($formKey = old('submitted_action') === $formIdentity ? old('idempotency_key') : $actionFormKeys[$action->value])
        <form class="primary-action" method="post" action="{{ route('orders.stock-rebuild.actions', [$order, $workflow['thread']->type->value, $action->value]) }}">
            @csrf
            <input type="hidden" name="submitted_action" value="{{ $formIdentity }}">
            <input type="hidden" name="idempotency_key" value="{{ $formKey }}">
            <input type="hidden" name="expected_lock_version" value="{{ $workflow['thread']->lock_version }}">
            @if($qrMode)<input type="hidden" name="return_qr_token" value="{{ $selectedItem->qr_token }}">@endif

            @if($action === \App\Enums\StockRebuildAction::SET_PL_STOCK_ETA)
                <label for="eta-{{ $workflow['thread']->id }}">{{ __('app.eta') }}</label>
                <input id="eta-{{ $workflow['thread']->id }}" type="datetime-local" name="eta" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" value="{{ old('submitted_action') === $formIdentity ? old('eta') : $workflow['thread']->eta?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') }}" required>
            @endif

            <button type="submit">{{ __('app.actions.'.$action->value) }}</button>
        </form>
    @empty
        <p class="muted">{{ __('app.no_action') }}</p>
    @endforelse
</section>
