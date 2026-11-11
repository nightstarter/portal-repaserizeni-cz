<?php

namespace App\Console\Commands;

use App\Application\Audit\AuditLogger;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateInitialAdmin extends Command
{
    protected $signature = 'portal:create-admin {--name=} {--email=} {--locale=cs}';

    protected $description = 'Create the first named AK_ADMIN account without exposing a password in shell history';

    public function handle(AuditLogger $audit): int
    {
        if (User::query()->where('role', UserRole::AK_ADMIN)->exists()) {
            $this->error('An AK_ADMIN account already exists. Use the authenticated user administration instead.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $email = (string) ($this->option('email') ?: $this->ask('E-mail'));
        $locale = (string) $this->option('locale');
        $password = (string) $this->secret('Password (minimum 12 characters, mixed case, number and symbol)');
        $confirmation = (string) $this->secret('Confirm password');
        $data = compact('name', 'email', 'locale', 'password');
        $data['password_confirmation'] = $confirmation;

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'locale' => ['required', 'in:cs,pl'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $organization = Organization::query()->where('code', OrganizationCode::AUTOKLUBY)->firstOrFail();
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => strtolower($email),
            'role' => UserRole::AK_ADMIN,
            'preferred_locale' => $locale,
            'password' => Hash::make($password),
            'active' => true,
            'must_change_password' => false,
        ]);
        $audit->record('INITIAL_ADMIN_CREATED', $user, [], ['email' => $user->email, 'role' => $user->role->value]);
        $this->info('Initial AK_ADMIN account created.');

        return self::SUCCESS;
    }
}
