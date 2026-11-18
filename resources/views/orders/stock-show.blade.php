@extends('layouts.app')
@section('content')
<div class="card" id="overview">
    <a href="{{ route('dashboard') }}">← {{ __('app.dashboard') }}</a>
    <h1>#{{ $order->order_no }} · {{ __('app.order_types.STOCK_REBUILD') }}</h1>
    <div class="meta">
        <span><strong>{{ __('app.product_code') }}</strong><br>{{ $order->product_code }}</span>
        <span><strong>{{ __('app.vehicle') }}</strong><br>{{ $order->vehicle ?: '—' }}</span>
        <span><strong>{{ __('app.technical_type') }}</strong><br>{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}</span>
        <span><strong>{{ __('app.routing') }}</strong><br>{{ $order->routing?->value ?: '—' }}</span>
    </div>
</div>

@php($coreBranch = $branches->first(fn($branch) => $branch['thread']->type === \App\Enums\ProcessThreadType::CORE_RETURN))
@php($mayManageBusinessFinance = auth()->user()->organization->code === \App\Enums\OrganizationCode::POLAND || auth()->user()->role === \App\Enums\UserRole::AK_ADMIN)
@php($hasFinanceSection = (bool) $coreBranch || ($mayManageBusinessFinance && $branches->contains(fn($branch) => in_array($branch['thread']->type, [\App\Enums\ProcessThreadType::STOCK_REQUEST, \App\Enums\ProcessThreadType::STOCK_REPLENISHMENT], true))))
<div class="section-nav" role="navigation" aria-label="{{ __('app.order_sections') }}">
    <a href="#overview">{{ __('app.section_overview') }}</a>
    @if($coreBranch)<a href="#thread-{{ $coreBranch['thread']->id }}">{{ __('app.section_return') }}</a>@endif
    @if($hasFinanceSection)<a href="#finance">{{ __('app.section_documents_finance') }}</a>@endif
    <a href="{{ route('orders.discussion.index', $order) }}">{{ __('app.discussion') }} @if($discussionUnreadCount > 0)<span class="unread-badge">{{ $discussionUnreadCount }}</span>@endif</a>
</div>

<section class="card stock-information">
    <h2>{{ __('app.stock_information') }}</h2>
    <p><span class="badge {{ $stockInformation['status']->value === 'STALE' ? 'waiting' : '' }}">{{ __('app.stock_availability.'.$stockInformation['status']->value) }}</span></p>
    @if($branches->contains(fn($branch) => $branch['thread']->state === 'PENDING_STOCK_CHECK'))
        <p><strong>{{ __('app.waiting_for_pohoda') }}</strong></p>
        <p>{{ $stockInformation['status']->value === 'STALE' ? __('app.pohoda_snapshot_stale') : __('app.pohoda_snapshot_missing') }}</p>
        <p class="muted">{{ __('app.no_manual_stock_decision') }}</p>
    @endif
    <p><strong>{{ __('app.last_valid_snapshot') }}:</strong> {{ $stockInformation['observed_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</p>
</section>

<h2>{{ __('app.parallel_branches') }}</h2>
@if($hasFinanceSection)<div id="finance"></div>@endif
<div class="branch-grid">
@foreach($branches as $branch)
    @php($thread = $branch['thread'])
    <article id="thread-{{ $thread->id }}" class="card branch-card {{ $thread->completed_at ? 'completed' : 'active' }}">
        <header>
            <h3>{{ __('app.thread_types.'.$thread->type->value) }}</h3>
            <span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span>
        </header>
        <dl class="branch-facts">
            <div><dt>{{ __('app.next_responsibility') }}</dt><dd>{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</dd></div>
            <div><dt>{{ __('app.eta') }}</dt><dd>{{ $thread->eta?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</dd></div>
            <div><dt>{{ __('app.last_event') }}</dt><dd>{{ $branch['last_event']?->performed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</dd></div>
        </dl>
        @if($thread->type === \App\Enums\ProcessThreadType::CORE_RETURN)
            @include('orders.partials.core-return-panel', ['workflow' => $branch])
        @else
            @include('orders.partials.stock-action-panel', ['workflow' => $branch])
            @if(in_array($thread->type, [\App\Enums\ProcessThreadType::STOCK_REQUEST, \App\Enums\ProcessThreadType::STOCK_REPLENISHMENT], true) && (auth()->user()->organization->code === \App\Enums\OrganizationCode::POLAND || auth()->user()->role === \App\Enums\UserRole::AK_ADMIN))
                @php($activeInvoice = $thread->documents->first(fn($document) => $document->type === \App\Enums\DocumentType::SUPPLIER_INVOICE && $document->status === \App\Enums\DocumentStatus::ACTIVE))
                <form method="post" enctype="multipart/form-data" action="{{ $activeInvoice ? route('documents.invoice.replace', [$order, $thread, $activeInvoice]) : route('documents.invoice.store', [$order, $thread]) }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $documentFormKeys[$thread->id] }}">
                    <input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
                    <label>{{ __('app.file') }}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
                    <label>{{ __('app.document_number') }}<input name="document_number" required></label>
                    <label>{{ __('app.document_date') }}<input type="date" name="document_date" required></label>
                    <label>{{ __('app.purchase_price') }}<input name="amount" required></label>
                    <label>{{ __('app.currency') }}<input name="currency" maxlength="3" required></label>
                    <label>{{ __('app.supplier_deposit_charged') }}<select name="deposit_charged"><option value="0">{{ __('app.no') }}</option><option value="1">{{ __('app.yes') }}</option></select></label>
                    <label>{{ __('app.supplier_deposit_amount') }}<input name="deposit_amount"></label>
                    @if($activeInvoice || auth()->user()->role === \App\Enums\UserRole::AK_ADMIN)<label>{{ __('app.reason') }}<textarea name="reason" required></textarea></label>@endif
                    <button>{{ $activeInvoice ? __('app.replace_invoice') : __('app.upload_invoice') }}</button>
                </form>
            @endif
        @endif
    </article>
@endforeach
</div>

<section class="card">
    <h2>{{ __('app.physical_items') }}</h2>
    <div class="table-scroll"><table class="responsive"><thead><tr><th>{{ __('app.item_role') }}</th><th>{{ __('app.location') }}</th><th>{{ __('app.related_branch') }}</th><th>QR</th></tr></thead><tbody>
    @forelse($order->items as $item)
        @php($relatedType = match($item->role) { \App\Enums\ItemRole::STOCK_ITEM => \App\Enums\ProcessThreadType::STOCK_REQUEST, \App\Enums\ItemRole::STOCK_REPLACEMENT => \App\Enums\ProcessThreadType::STOCK_REPLENISHMENT, \App\Enums\ItemRole::CORE_RETURN => \App\Enums\ProcessThreadType::CORE_RETURN, default => null })
        <tr>
            <td data-label="{{ __('app.item_role') }}">{{ __('app.item_roles.'.$item->role->value) }}</td>
            <td data-label="{{ __('app.location') }}">{{ __('app.locations.'.$item->current_location) }}</td>
            <td data-label="{{ __('app.related_branch') }}">{{ $relatedType ? __('app.thread_types.'.$relatedType->value) : '—' }}</td>
            <td data-label="QR"><a class="button compact" href="{{ route('items.qr', $item->qr_token) }}">{{ __('app.open_qr_detail') }}</a></td>
        </tr>
    @empty
        <tr><td colspan="4">{{ __('app.no_items') }}</td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="card closure-card">
    <h2>{{ __('app.order_completion') }}</h2>
    @php($completedCount = $branches->filter(fn($branch) => $branch['thread']->completed_at !== null)->count())
    @php($activeBranches = $branches->filter(fn($branch) => $branch['thread']->completed_at === null))
    <p>{{ __('app.completed_required_branches') }}: <strong>{{ $completedCount }}</strong></p>
    <p>{{ __('app.incomplete_required_branches') }}: <strong>{{ $activeBranches->count() }}</strong></p>
    @if($order->closed_at)
        <p class="status">{{ __('app.order_is_closed') }}</p>
    @else
        <p class="notice"><strong>{{ __('app.order_stays_open') }}</strong></p>
        @if($activeBranches->isNotEmpty())
            <ul>@foreach($activeBranches as $branch)<li>{{ __('app.thread_types.'.$branch['thread']->type->value) }} — {{ __('app.thread_states.'.$branch['thread']->state) }}</li>@endforeach</ul>
        @else
            <p class="muted">{{ __('app.order_has_other_required_branches') }}</p>
        @endif
    @endif
</section>

@if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && $order->customer)
<section class="card"><h2>{{ __('app.customer') }}</h2><p>{{ $order->customer->name }}<br>{{ $order->customer->email }}<br>{{ $order->customer->phone }}</p></section>
@endif

@include('orders.partials.stock-timeline')
@endsection
