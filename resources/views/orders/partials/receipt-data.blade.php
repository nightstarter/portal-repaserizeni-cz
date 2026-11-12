@if($item && $item->inspection_result)
<section class="card">
    <h2>{{ __('app.receipt_check') }}</h2>
    <div class="meta">
        <span><strong>{{ __('app.inspection_result') }}</strong><br>{{ __('app.inspection_results.'.$item->inspection_result) }}</span>
        <span><strong>{{ __('app.fault') }}</strong><br>{{ __('app.faults.'.($item->technical_attributes['fault_code'] ?? 'UNKNOWN')) }}</span>
        <span><strong>{{ __('app.axial_rods') }}</strong><br>{{ ($item->technical_attributes['axial_rods'] ?? false) ? __('app.yes') : __('app.no') }}</span>
        <span><strong>{{ __('app.axial_rods_surcharge') }}</strong><br>{{ $item->axialRodsSurchargeCzk() }} Kč</span>
    </div>
    @if($item->technical_attributes['condition_description'] ?? null)<p><strong>{{ __('app.condition_description') }}:</strong> {{ $item->technical_attributes['condition_description'] }}</p>@endif
    @if($item->technical_attributes['fault_note'] ?? null)<p><strong>{{ __('app.fault_note') }}:</strong> {{ $item->technical_attributes['fault_note'] }}</p>@endif
</section>
@endif
