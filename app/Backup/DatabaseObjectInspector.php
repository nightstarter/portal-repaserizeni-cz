<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseObjectInspector
{
    /** @return array{triggers:int,routines:int,events:int} */
    public function inspect(): array
    {
        $override = app()->environment('testing') ? config('backups.testing_database_objects') : null;
        if (is_array($override)) {
            return $this->normalize($override);
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            if (app()->environment('testing')) {
                return ['triggers' => 0, 'routines' => 0, 'events' => 0];
            }
            throw new RuntimeException('MariaDB logical object inventory requires a MySQL connection.');
        }

        $database = (string) DB::connection()->getDatabaseName();

        return [
            'triggers' => (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->count(),
            'routines' => (int) DB::table('information_schema.ROUTINES')->where('ROUTINE_SCHEMA', $database)->count(),
            'events' => (int) DB::table('information_schema.EVENTS')->where('EVENT_SCHEMA', $database)->count(),
        ];
    }

    private function normalize(array $values): array
    {
        return [
            'triggers' => max(0, (int) ($values['triggers'] ?? 0)),
            'routines' => max(0, (int) ($values['routines'] ?? 0)),
            'events' => max(0, (int) ($values['events'] ?? 0)),
        ];
    }
}
