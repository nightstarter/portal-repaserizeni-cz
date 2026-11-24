<?php

namespace App\Operations;

use App\Models\MonitorRun;
use App\Models\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class MonitoringRunner
{
    public function __construct(
        private readonly MonitoringSuite $suite,
        private readonly OperationalAlertManager $alerts,
        private readonly OperationalAlertRouter $router,
    ) {}

    public function run(?string $only = null, bool $noNotify = false): MonitorExecution
    {
        $lock = Cache::lock('ops-monitor-global', max(1, (int) config('operations.monitoring.lock_seconds', 240)));
        if (! $lock->get()) {
            return $this->contended($only, $noNotify);
        }

        try {
            return $this->execute($only, $noNotify);
        } finally {
            $lock->release();
        }
    }

    private function execute(?string $only, bool $noNotify): MonitorExecution
    {
        $run = MonitorRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'status' => 'RUNNING',
            'requested_check' => $only,
            'notifications_suppressed' => $noNotify,
            'started_at' => now(),
        ]);
        try {
            $checks = $this->suite->run($only, $run->id);
            $changes = $this->alerts->apply($checks, $run);
            $this->router->route($changes, $noNotify);
            $status = $this->overall($checks);
            $counts = collect($checks)->countBy('severity');
            $run->forceFill([
                'status' => $status,
                'ok_count' => (int) ($counts[MonitorCheck::OK] ?? 0),
                'info_count' => (int) ($counts[MonitorCheck::INFO] ?? 0),
                'warn_count' => (int) ($counts[MonitorCheck::WARN] ?? 0),
                'error_count' => (int) ($counts[MonitorCheck::ERROR] ?? 0),
                'critical_count' => (int) ($counts[MonitorCheck::CRITICAL] ?? 0),
                'safe_results' => array_map(fn (MonitorCheck $check): array => $check->toArray(), $checks),
                'completed_at' => now(),
            ])->save();
            $this->heartbeat($run);

            return new MonitorExecution($status, $checks, $run->run_uuid);
        } catch (Throwable) {
            $run->forceFill(['status' => MonitorCheck::CRITICAL, 'failure_code' => 'MONITOR_INTERNAL_FAILURE', 'completed_at' => now()])->save();
            $this->heartbeat($run);
            $check = new MonitorCheck('monitor_internal', MonitorCheck::CRITICAL, 'Monitorovací běh selhal; interní detail nebyl zveřejněn.', ['failure_code' => 'MONITOR_INTERNAL_FAILURE']);
            try {
                $changes = $this->alerts->apply([$check], $run);
                $this->router->route($changes, $noNotify);
            } catch (Throwable) {
                // The safe run record remains the final fallback.
            }

            return new MonitorExecution(MonitorCheck::CRITICAL, [$check], $run->run_uuid);
        }
    }

    private function contended(?string $only, bool $noNotify): MonitorExecution
    {
        $uuid = null;
        if (Schema::hasTable('monitor_runs')) {
            $run = MonitorRun::query()->create([
                'run_uuid' => (string) Str::uuid(),
                'status' => MonitorCheck::CRITICAL,
                'requested_check' => $only,
                'notifications_suppressed' => $noNotify,
                'lock_contended' => true,
                'failure_code' => 'MONITOR_LOCK_CONTENDED',
                'started_at' => now(),
                'completed_at' => now(),
            ]);
            $uuid = $run->run_uuid;
        }
        $check = new MonitorCheck('monitor_overlap', MonitorCheck::CRITICAL, 'Monitorovací běh byl odmítnut kvůli aktivnímu globálnímu locku.', ['lock_contended' => true]);

        return new MonitorExecution(MonitorCheck::CRITICAL, [$check], $uuid, true);
    }

    /** @param list<MonitorCheck> $checks */
    private function overall(array $checks): string
    {
        foreach ([MonitorCheck::CRITICAL, MonitorCheck::ERROR, MonitorCheck::WARN, MonitorCheck::INFO] as $severity) {
            if (collect($checks)->contains(fn (MonitorCheck $check): bool => $check->severity === $severity)) {
                return $severity;
            }
        }

        return MonitorCheck::OK;
    }

    private function heartbeat(MonitorRun $run): void
    {
        if (! Schema::hasTable('scheduler_heartbeats')) {
            return;
        }
        SchedulerHeartbeat::query()->updateOrCreate(['key' => 'laravel'], [
            'monitor_enabled' => (bool) config('operations.monitoring.enabled'),
            'last_monitor_run_id' => $run->id,
            'last_monitor_status' => $run->status,
            'updated_at' => now(),
        ]);
    }
}
