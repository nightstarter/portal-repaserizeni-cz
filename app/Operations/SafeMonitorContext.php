<?php

namespace App\Operations;

final class SafeMonitorContext
{
    private const DENIED_KEY_PARTS = ['secret', 'password', 'token', 'credential', 'dsn', 'path', 'file', 'email', 'recipient', 'payload', 'content', 'exception', 'trace', 'host', 'database', 'username'];

    public function sanitize(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1 || $this->denied($key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 120 && ! str_contains($value, '@') && ! str_contains($value, '/') && ! str_contains($value, '\\')) {
                $safe[$key] = $value;
            } elseif (is_array($value) && count($value) <= 20) {
                $nested = $this->sanitize($value);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }
            }
        }

        return $safe;
    }

    private function denied(string $key): bool
    {
        foreach (self::DENIED_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}
