<!doctype html><html lang="cs"><body>
<h1>{{ __('app.buyback_email_heading') }}</h1>
<p>{{ __('app.buyback_email_order', ['order' => $orderNo]) }}</p>
<p><strong>{{ $formattedAmount }} {{ $currency }}</strong></p>
<p>{{ __('app.buyback_email_contact') }}</p>
</body></html>
