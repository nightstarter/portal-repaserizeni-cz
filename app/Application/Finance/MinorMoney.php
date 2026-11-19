<?php

namespace App\Application\Finance;

use Illuminate\Validation\ValidationException;

final class MinorMoney
{
    public static function parse(string $amount, string $field = 'amount'): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($amount));
        if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $normalized)) {
            throw ValidationException::withMessages([$field => __('app.buyback_invalid_amount')]);
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        if (strlen($whole) > 13) {
            throw ValidationException::withMessages([$field => __('app.buyback_invalid_amount')]);
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function format(int $minor): string
    {
        return intdiv($minor, 100).','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
