<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);
        $request->user()->update(['password' => $data['password'], 'must_change_password' => false]);
        $audit->record('USER_PASSWORD_CHANGED', $request->user());

        return redirect()->route('dashboard')->with('status', __('app.password_changed'));
    }
}
