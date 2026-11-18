@php($thread = $workflow['thread'])
<div class="notice">
    <strong>{{ __('app.core_return_location') }}:</strong> {{ $workflow['item'] ? __('app.locations.'.$workflow['item']->current_location) : '—' }}<br>
    @if($workflow['inspection'])
        <strong>{{ __('app.core_inspection') }}:</strong> {{ __('app.core_piece_results.'.$workflow['inspection']->piece_result->value) }}, {{ __('app.core_transport_conditions.'.$workflow['inspection']->transport_condition->value) }}
    @endif
</div>
@foreach($workflow['allowed_actions'] as $action)
<form class="primary-action" method="post" action="{{ route('orders.core-return.actions', [$order, $action->value]) }}">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $coreActionFormKeys[$action->value] }}">
    <input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
    @if($action === \App\Enums\CoreReturnAction::CONFIRM_CORE_RECEIPT_AK)
        <label>{{ __('app.core_piece') }}<select name="piece_result"><option value="CORRECT_PIECE">{{ __('app.core_piece_results.CORRECT_PIECE') }}</option><option value="DIFFERENT_PIECE">{{ __('app.core_piece_results.DIFFERENT_PIECE') }}</option></select></label>
        <label>{{ __('app.transport_condition') }}<select name="transport_condition">@foreach(\App\Enums\CoreTransportCondition::cases() as $condition)<option value="{{ $condition->value }}">{{ __('app.core_transport_conditions.'.$condition->value) }}</option>@endforeach</select></label>
        @foreach(['accident','disassembled','corrosion','axial_rods'] as $flag)<label class="choice"><input type="hidden" name="{{ $flag }}" value="0"><input type="checkbox" name="{{ $flag }}" value="1"><span>{{ __('app.'.$flag) }}</span></label>@endforeach
        <label>{{ __('app.note') }}<textarea name="note"></textarea></label>
    @elseif($action === \App\Enums\CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN)
        <label>{{ __('app.approved_return_amount') }}<input name="approved_return_amount" inputmode="decimal" required></label>
        <label>{{ __('app.currency') }}<input name="currency" value="{{ $workflow['original_deposit']?->currency }}" readonly required></label>
        <label>{{ __('app.reason') }}<textarea name="reason" required></textarea></label>
    @endif
    @if(auth()->user()->role === \App\Enums\UserRole::AK_ADMIN && in_array($action, [\App\Enums\CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, \App\Enums\CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN, \App\Enums\CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, \App\Enums\CoreReturnAction::CONFIRM_CREDIT_PAID], true))
        <label>{{ __('app.override_reason') }}<textarea name="override_reason" required></textarea></label>
    @endif
    <button>{{ __('app.actions.'.$action->value) }}</button>
</form>
@endforeach
@if(in_array($thread->state, ['DEPOSIT_FULL_APPROVED','DEPOSIT_REDUCED_APPROVED'], true))
<form method="post" enctype="multipart/form-data" action="{{ route('documents.credit.store', [$order, $thread]) }}">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $documentFormKeys[$thread->id] }}-credit">
    <input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
    <label>{{ __('app.file') }}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
    <label>{{ __('app.document_number') }}<input name="document_number" required></label>
    <label>{{ __('app.document_date') }}<input type="date" name="document_date" required></label>
    <label>{{ __('app.amount') }}<input name="amount" value="{{ $workflow['approved_return']?->amount }}" readonly required></label>
    <label>{{ __('app.currency') }}<input name="currency" value="{{ $workflow['approved_return']?->currency }}" readonly required></label>
    @if(auth()->user()->role === \App\Enums\UserRole::AK_ADMIN)<label>{{ __('app.override_reason') }}<textarea name="reason" required></textarea></label>@endif
    <button>{{ __('app.upload_credit_note') }}</button>
</form>
@endif
@php($activeCredit = $workflow['active_documents']->firstWhere('type', \App\Enums\DocumentType::SUPPLIER_CREDIT_NOTE))
@if($thread->state === 'CREDIT_ISSUED' && $activeCredit)
<form method="post" enctype="multipart/form-data" action="{{ route('documents.credit.replace', [$order, $thread, $activeCredit]) }}">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $documentFormKeys[$thread->id] }}-credit-replace">
    <input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
    <label>{{ __('app.file') }}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
    <label>{{ __('app.document_number') }}<input name="document_number" required></label>
    <label>{{ __('app.document_date') }}<input type="date" name="document_date" required></label>
    <label>{{ __('app.amount') }}<input name="amount" value="{{ $workflow['approved_return']?->amount }}" readonly required></label>
    <label>{{ __('app.currency') }}<input name="currency" value="{{ $workflow['approved_return']?->currency }}" readonly required></label>
    <label>{{ __('app.reason') }}<textarea name="reason" required></textarea></label>
    <button>{{ __('app.replace_credit_note') }}</button>
</form>
@endif
<h4>{{ __('app.finance_ledger') }}</h4>
<div class="table-scroll"><table><tbody>@foreach($workflow['finance'] as $entry)<tr><td>{{ $entry->type->value }}</td><td>{{ $entry->amount }} {{ $entry->currency }}</td><td>{{ $entry->actorUser?->name ?? '—' }}</td><td>{{ $entry->recorded_at?->format('d.m.Y H:i') }}</td></tr>@endforeach</tbody></table></div>
<h4>{{ __('app.documents') }}</h4>
<div class="table-scroll"><table><tbody>@foreach($workflow['documents'] as $document)<tr><td>{{ __('app.document_types.'.$document->type->value) }}</td><td>{{ $document->document_number }}</td><td>{{ $document->status->value }}</td><td>@if($document->status === \App\Enums\DocumentStatus::ACTIVE)<a class="button compact" href="{{ route('documents.download', $document) }}">{{ __('app.download') }}</a>@endif</td></tr>@endforeach</tbody></table></div>
