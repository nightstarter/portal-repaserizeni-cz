<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditLogger;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $this->authorizeManage();

        return view('users.index', [
            'users' => User::query()->with('organization')->orderBy('name')->get(),
            'organizations' => Organization::query()->where('active', true)->orderBy('name')->get(),
            'roles' => UserRole::cases(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $data = $this->validated($request, creating: true);
        $organization = Organization::query()->findOrFail($data['organization_id']);
        $role = UserRole::from($data['role']);
        abort_unless($role->belongsTo($organization->code), 422, __('app.invalid_role_organization'));
        $data['must_change_password'] = true;
        $data['active'] = true;
        $user = User::query()->create($data);
        $audit->record('USER_CREATED', $user, [], $user->only(['organization_id', 'name', 'email', 'role', 'preferred_locale', 'active']));

        return back()->with('status', __('app.user_created'));
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeManage();
        $data = $this->validated($request, creating: false, user: $user);
        $organization = Organization::query()->findOrFail($data['organization_id']);
        abort_unless(UserRole::from($data['role'])->belongsTo($organization->code), 422, __('app.invalid_role_organization'));
        abort_if($request->user()->is($user) && ! $data['active'], 422, __('app.cannot_deactivate_self'));
        $old = $user->only(['organization_id', 'name', 'email', 'role', 'preferred_locale', 'active']);
        $user->update($data);
        $audit->record('USER_UPDATED', $user, $old, $user->only(array_keys($old)));

        return back()->with('status', __('app.user_updated'));
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('manage-users'), 403);
    }

    private function validated(Request $request, bool $creating, ?User $user = null): array
    {
        return $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'preferred_locale' => ['required', Rule::in(['cs', 'pl'])],
            'active' => [$creating ? 'nullable' : 'required', 'boolean'],
            'password' => [$creating ? 'required' : 'nullable', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);
    }
}
