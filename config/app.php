<?php

return [
    'name' => env('APP_NAME', 'Repase řízení'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Prague'),
    'locale' => env('APP_LOCALE', 'cs'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'cs'),
    'faker_locale' => 'cs_CZ',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
];
