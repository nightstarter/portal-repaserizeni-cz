<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['cs', 'pl'])]]);
        $old = $request->user()->preferred_locale;
        $request->user()->update(['preferred_locale' => $data['locale']]);
        $request->session()->put('locale', $data['locale']);
        $audit->record('USER_LOCALE_CHANGED', $request->user(), ['preferred_locale' => $old], $data);

        return back();
    }
}
