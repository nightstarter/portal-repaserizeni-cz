<?php

namespace PortalRepase\Bridge;

final class CanonicalRequest
{
    public static function query(array $query)
    {
        $pairs = array();
        self::flatten($query, '', $pairs);
        usort($pairs, function ($left, $right) {
            $name = strcmp($left[0], $right[0]);
            return $name !== 0 ? $name : strcmp($left[1], $right[1]);
        });
        $encoded = array();
        foreach ($pairs as $pair) {
            $encoded[] = rawurlencode($pair[0]).'='.rawurlencode($pair[1]);
        }

        return implode('&', $encoded);
    }

    private static function flatten(array $values, $prefix, array &$pairs)
    {
        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                self::flatten($value, $name, $pairs);
            } else {
                $pairs[] = array($name, (string) $value);
            }
        }
    }

    public static function value($method, $path, array $query, $timestamp, $nonce, $rawBody)
    {
        return implode("\n", array(
            strtoupper($method), $path, self::query($query), (string) $timestamp,
            (string) $nonce, hash('sha256', $rawBody)
        ));
    }

    public static function signature($secret, $method, $path, array $query, $timestamp, $nonce, $rawBody)
    {
        return hash_hmac('sha256', self::value($method, $path, $query, $timestamp, $nonce, $rawBody), $secret);
    }
}
