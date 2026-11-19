<?php

namespace App\Integrations\Pohoda;

use Illuminate\Http\Request;

final class BridgeCanonicalRequest
{
    public static function canonicalString(Request $request, string $timestamp, string $nonce): string
    {
        return implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            self::canonicalQuery($request->query()),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);
    }

    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        self::flatten($query, '', $pairs);
        usort($pairs, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $pairs,
        ));
    }

    private static function flatten(array $values, string $prefix, array &$pairs): void
    {
        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                self::flatten($value, $name, $pairs);
            } else {
                $pairs[] = [$name, (string) $value];
            }
        }
    }
}
