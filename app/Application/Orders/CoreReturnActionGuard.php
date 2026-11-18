<?php

namespace App\Application\Orders;

use App\Enums\CoreReturnAction;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class CoreReturnActionGuard
{
    private const AK_ACTIONS = [
        CoreReturnAction::CONFIRM_CORE_RECEIPT_AK,
        CoreReturnAction::SEND_TO_POLAND,
    ];

    private const PL_ACTIONS = [
        CoreReturnAction::CONFIRM_CORE_RECEIPT_PL,
        CoreReturnAction::RECORD_SUPPLIER_PURCHASE_PRICE,
        CoreReturnAction::RECORD_SUPPLIER_DEPOSIT_ORIGINAL,
        CoreReturnAction::RECORD_NO_SUPPLIER_DEPOSIT,
        CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN,
        CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN,
        CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT,
        CoreReturnAction::ISSUE_SUPPLIER_CREDIT_NOTE,
        CoreReturnAction::CONFIRM_CREDIT_PAID,
    ];

    public function assertMayPerform(User $actor, CoreReturnCommand $command): bool
    {
        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }

        if (in_array($command->action, [CoreReturnAction::RECORD_FINANCE_CORRECTION, CoreReturnAction::REVERSE_FINANCE_ENTRY], true)) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }
            if (trim((string) ($command->data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => 'reason'])]);
            }

            return true;
        }

        if (in_array($command->action, self::AK_ACTIONS, true)) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY
                || ! in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true)) {
                throw new AuthorizationException;
            }

            return false;
        }

        if (in_array($command->action, self::PL_ACTIONS, true)
            && $actor->organization->code === OrganizationCode::POLAND
            && in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true)) {
            return false;
        }

        $financialDecision = in_array($command->action, [
            CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN,
            CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN,
            CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT,
            CoreReturnAction::ISSUE_SUPPLIER_CREDIT_NOTE,
            CoreReturnAction::CONFIRM_CREDIT_PAID,
        ], true);
        if ($financialDecision && $actor->organization->code === OrganizationCode::AUTOKLUBY && $actor->role === UserRole::AK_ADMIN) {
            if (trim((string) ($command->data['override_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['override_reason' => __('validation.required', ['attribute' => 'override_reason'])]);
            }

            return true;
        }

        throw new AuthorizationException;
    }
}
