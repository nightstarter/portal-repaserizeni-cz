@extends('layouts.app')
@section('content')
<h1>Produkční připravenost</h1>
<div class="notice {{ $overall === 'ERROR' ? 'error' : '' }}">
    <strong>Celkový stav: {{ $overall }}</strong>
    <p>Read-only přehled. Neprovádí opravy, migrace, importy, snapshoty ani odesílání e-mailů.</p>
</div>

<section class="card">
    <h2>Provozní preflight</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Stav</th><th>Kontrola</th><th>Výsledek</th><th>Bezpečné údaje</th></tr></thead>
            <tbody>
            @foreach($checks as $check)
                <tr>
                    <td><strong>{{ $check->status }}</strong></td>
                    <td><code>{{ $check->id }}</code></td>
                    <td>{{ $check->message }}</td>
                    <td>
                        @foreach($check->context as $key => $value)
                            <span>{{ $key }}={{ is_bool($value) ? ($value ? 'ano' : 'ne') : (is_array($value) ? count($value) : ($value ?? '—')) }}</span>@if(!$loop->last) · @endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Známá provozní omezení</h2>
    <ul>
        <li>Stav Windows Task Scheduleru a jeho Last Run Result musí provozovatel ověřit ručně.</li>
        <li>PHP CLI 7.4.33 a Windows Server 2012 R2 na HPSERVERU jsou přijatý technický dluh a WARN, nikoli blokátor 8A.</li>
        <li>Portál nemá přímé SQL spojení do Pohody; konektor je read-only.</li>
        <li>Ceny se přes bridge nezapisují a přecenění zůstává v externím systému.</li>
        <li>Legacy import a oba typy produkčních e-mailů zůstávají vypnuté.</li>
    </ul>
</section>
@endsection
