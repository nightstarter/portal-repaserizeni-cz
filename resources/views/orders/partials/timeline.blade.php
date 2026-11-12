<section class="card">
    <h2>{{ __('app.timeline') }}</h2>
    <ol class="timeline">
        @forelse($timeline as $entry)
            <li>
                <time class="muted" datetime="{{ $entry['performed_at']->toAtomString() }}">{{ $entry['performed_at']->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</time>
                <h3>{{ __('app.actions.'.$entry['action']) }}</h3>
                <div>{{ $entry['from_state'] ? __('app.thread_states.'.$entry['from_state']) : '—' }} → <strong>{{ __('app.thread_states.'.$entry['to_state']) }}</strong></div>
                <div class="muted">{{ __('app.performed_by') }}: {{ $entry['actor_organization'] ? __('app.responsible_parties.'.$entry['actor_organization']) : '—' }}@if($entry['actor_name']) · {{ $entry['actor_name'] }}@endif</div>
                @if($entry['metadata'])
                    <div class="timeline-meta">
                        @foreach($entry['metadata'] as $key => $value)
                            <span>{{ __('app.metadata_labels.'.$key) }}:
                                @if($key === 'inspection_result') {{ __('app.inspection_results.'.$value) }}
                                @elseif($key === 'fault_code') {{ __('app.faults.'.$value) }}
                                @elseif(is_bool($value)) {{ $value ? __('app.yes') : __('app.no') }}
                                @elseif($key === 'axial_rods_surcharge_czk') {{ $value }} Kč
                                @else {{ $value ?: '—' }}
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif
                @if($entry['reason'])<p><strong>{{ __('app.reason') }}:</strong> {{ $entry['reason'] }}</p>@endif
                @if($entry['audited'])<span class="badge done">{{ __('app.audit_recorded') }}</span>@endif
            </li>
        @empty
            <li>{{ __('app.no_timeline') }}</li>
        @endforelse
    </ol>
</section>
