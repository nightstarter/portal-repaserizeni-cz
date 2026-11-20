<?php

namespace PortalRepase\Bridge;

final class Util
{
    public static function uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    public static function nonce()
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function utcNow()
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function json($value)
    {
        $normalized = self::normalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new BridgeException('JSON_ENCODE_FAILED');
        }

        return $json;
    }

    private static function normalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! self::isList($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function isList(array $value)
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    public static function atomicWrite($path, $contents)
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BridgeException('LOCAL_DIRECTORY_CREATE_FAILED');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new BridgeException('LOCAL_ATOMIC_WRITE_FAILED');
        }
    }

    public static function backoffSeconds($attempt, $base, $maximum, $jitter = null)
    {
        $power = min(20, max(0, (int) $attempt - 1));
        $delay = min((int) $maximum, (int) $base * (2 ** $power));
        $range = max(1, (int) floor($delay * 0.2));
        $delta = $jitter === null ? random_int(-$range, $range) : max(-$range, min($range, (int) $jitter));

        return max(1, $delay + $delta);
    }
}
