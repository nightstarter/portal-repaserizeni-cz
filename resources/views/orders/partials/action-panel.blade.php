<section class="card action-card mobile-primary">
    <h2>{{ __('app.what_now') }}</h2>
    <p><span class="badge waiting">{{ __('app.thread_states.'.$workflow['thread']->state) }}</span></p>

    @forelse($workflow['allowed_actions'] as $action)
        @php($formKey = old('submitted_action') === $action->value ? old('idempotency_key') : $actionFormKeys[$action->value])
        <form class="primary-action" method="post" action="{{ route('orders.custom-rebuild.actions', [$order, $action->value]) }}">
            @csrf
            <input type="hidden" name="submitted_action" value="{{ $action->value }}">
            <input type="hidden" name="idempotency_key" value="{{ $formKey }}">
            <input type="hidden" name="expected_lock_version" value="{{ $workflow['thread']->lock_version }}">
            @if($qrMode)<input type="hidden" name="return_qr_token" value="{{ $selectedItem->qr_token }}">@endif

            @if($action === \App\Enums\CustomRebuildAction::CONFIRM_AK_RECEIPT)
                <fieldset>
                    <legend>{{ __('app.actions.'.$action->value) }}</legend>
                    <label>{{ __('app.inspection_result') }}</label>
                    <div class="choice-grid">
                        <label class="choice"><input type="radio" name="inspection_result" value="CORRECT" @checked(old('inspection_result', 'CORRECT') === 'CORRECT') required><span>{{ __('app.correct_item') }}</span></label>
                        <label class="choice"><input type="radio" name="inspection_result" value="DIFFERENT" @checked(old('inspection_result') === 'DIFFERENT') required><span>{{ __('app.different_item') }}</span></label>
                    </div>

                    <label for="condition_description">{{ __('app.condition_description') }}</label>
                    <textarea id="condition_description" name="condition_description">{{ old('condition_description') }}</textarea>

                    <label for="fault_code">{{ __('app.fault') }}</label>
                    <select id="fault_code" name="fault_code" required>
                        @foreach(\App\Enums\RebuildFaultCode::cases() as $fault)
                            <option value="{{ $fault->value }}" @selected(old('fault_code') === $fault->value)>{{ __('app.faults.'.$fault->value) }}</option>
                        @endforeach
                    </select>

                    <label for="routing">{{ __('app.confirm_routing') }}</label>
                    <select id="routing" name="routing" required>
                        @foreach(\App\Enums\RoutingCode::cases() as $routing)
                            <option value="{{ $routing->value }}" @selected(old('routing', $order->routing?->value) === $routing->value)>{{ $routing->value }}</option>
                        @endforeach
                    </select>

                    <label class="choice"><input type="hidden" name="axial_rods" value="0"><input type="checkbox" name="axial_rods" value="1" @checked(old('axial_rods'))><span>{{ __('app.axial_rods') }}</span></label>

                    <label for="fault_note">{{ __('app.fault_note') }}</label>
                    <textarea id="fault_note" name="fault_note">{{ old('fault_note') }}</textarea>
                </fieldset>
            @endif

            <button type="submit">{{ __('app.actions.'.$action->value) }}</button>
        </form>
    @empty
        <p class="muted">{{ __('app.no_action') }}</p>
    @endforelse

    @if(isset($actionFormKeys[\App\Enums\CustomRebuildAction::ADMIN_OVERRIDE->value]))
        <details>
            <summary>{{ __('app.admin_override') }}</summary>
            <form method="post" action="{{ route('orders.custom-rebuild.actions', [$order, \App\Enums\CustomRebuildAction::ADMIN_OVERRIDE->value]) }}">
                @csrf
                <input type="hidden" name="submitted_action" value="ADMIN_OVERRIDE">
                <input type="hidden" name="idempotency_key" value="{{ old('submitted_action') === 'ADMIN_OVERRIDE' ? old('idempotency_key') : $actionFormKeys['ADMIN_OVERRIDE'] }}">
                <input type="hidden" name="expected_lock_version" value="{{ $workflow['thread']->lock_version }}">
                @if($qrMode)<input type="hidden" name="return_qr_token" value="{{ $selectedItem->qr_token }}">@endif
                <label for="target_state">{{ __('app.target_state') }}</label>
                <select id="target_state" name="target_state" required>
                    @foreach($overrideStates as $state)
                        <option value="{{ $state->value }}" @selected(old('target_state') === $state->value)>{{ __('app.thread_states.'.$state->value) }}</option>
                    @endforeach
                </select>
                <label for="reason">{{ __('app.reason') }}</label>
                <textarea id="reason" name="reason" required>{{ old('reason') }}</textarea>
                <label class="choice"><input type="checkbox" name="override_confirmed" value="1" required><span>{{ __('app.confirm_override') }}</span></label>
                <button class="danger" type="submit">{{ __('app.admin_override') }}</button>
            </form>
        </details>
    @endif
</section>
