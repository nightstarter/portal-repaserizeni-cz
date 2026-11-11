<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->preferred_locale ?? $request->session()->get('locale', config('app.locale'));
        app()->setLocale(in_array($locale, ['cs', 'pl'], true) ? $locale : 'cs');

        return $next($request);
    }
}
