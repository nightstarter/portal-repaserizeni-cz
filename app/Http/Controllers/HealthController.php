<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): Response
    {
        try {
            DB::connection()->select('SELECT 1');
            $ready = is_dir(storage_path('framework')) && is_writable(storage_path('framework'));
        } catch (Throwable) {
            $ready = false;
        }

        return response($ready ? 'OK' : 'UNAVAILABLE', $ready ? 200 : 503)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
