@php($thread = $warranty['thread'])
<article id="warranty-thread-{{ $thread->id }}" class="card warranty-cycle {{ $thread->completed_at ? 'completed' : 'active' }}">
    <header class="section-heading"><div><h3>{{ __('app.warranty_number', ['number' => $thread->warranty_cycle]) }}</h3><span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span></div>@if(!$thread->completed_at)<strong class="active-label">{{ __('app.active_warranty') }}</strong>@endif</header>
    <dl class="branch-facts">
        <div><dt>{{ __('app.created_at') }}</dt><dd>{{ $thread->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</dd></div>
        <div><dt>{{ __('app.completed_at') }}</dt><dd>{{ $thread->completed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</dd></div>
        <div><dt>{{ __('app.next_responsibility') }}</dt><dd>{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</dd></div>
    </dl>
    @include('orders.partials.warranty-action-panel')
    @if($warranty['shipments']->isNotEmpty())
        <h4>{{ __('app.related_shipments') }}</h4><ul>@foreach($warranty['shipments'] as $shipment)<li><a href="{{ route('shipments.show', $shipment) }}">{{ $shipment->public_number }}</a> · {{ __('app.shipment_statuses.'.$shipment->status->value) }}</li>@endforeach</ul>
    @endif
    <h4>{{ __('app.history') }}</h4>
    <ol class="timeline warranty-timeline">
    @forelse($warranty['timeline'] as $event)
        <li><h5>{{ __('app.warranty_actions.'.$event['action']) }}</h5><p><span class="badge">{{ __('app.thread_states.'.$event['to_state']) }}</span></p><p class="muted">{{ $event['performed_at']->timezone(config('app.timezone'))->format('d.m.Y H:i') }} · {{ $event['actor_organization'] }}</p>
        @if($event['reason'])<p class="warranty-note">{{ $event['reason'] }}</p>@endif
        @if(isset($event['metadata']['shipment_public_number']))<p>{{ __('app.shipment') }}: {{ $event['metadata']['shipment_public_number'] }}</p>@endif
        </li>
    @empty<li>{{ __('app.no_warranty_history') }}</li>@endforelse
    </ol>
</article>
