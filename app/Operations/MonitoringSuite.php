<?php

namespace App\Operations;

use App\Models\MonitorRun;
use App\Models\OutboxEvent;
use App\Models\SchedulerHeartbeat;
use App\Operations\Contracts\TlsCertificateInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MonitoringSuite
{
    private const CRITICAL_PREFLIGHT_KEYS = [
        'application_environment', 'https_and_session', 'database', 'storage_framework',
        'storage_logs', 'bootstrap_cache', 'private_storage', 'disk_space',
    ];

    public function __construct(
        private readonly ProductionPreflight $preflight,
        private readonly SafeMonitorContext $safeContext,
        private readonly PrivateStorageHealth $privateStorage,
        private readonly TlsCertificateInspector $tls,
    ) {}

    /** @return list<MonitorCheck> */
    public function run(?string $only = null, ?int $currentRunId = null): array
    {
        $checks = collect($this->preflight->run())
            ->map(fn (PreflightCheck $check): MonitorCheck => $this->fromPreflight($check));
        $checks->push($this->protect('scheduler_heartbeat', fn (): MonitorCheck => $this->schedulerHeartbeat()));
        $checks->push($this->protect('monitor_last_success', fn (): MonitorCheck => $this->monitorFreshness($currentRunId)));
        $checks->push($this->protect('monitor_overlap', fn (): MonitorCheck => $this->monitorOverlap($currentRunId)));
        $checks->push($this->protect('integration_delivery', fn (): MonitorCheck => $this->integrationDelivery()));
        $checks->push($this->privateStorage->check());
        $checks->push($this->protect('tls_certificate', fn (): MonitorCheck => $this->tlsCertificate()));

        if ($only !== null) {
            $checks = $checks->where('key', $only);
            if ($checks->isEmpty()) {
                return [new MonitorCheck('monitor_request', MonitorCheck::ERROR, 'Požadovaný monitorovací check key neexistuje.', ['requested_key_valid' => false], false)];
            }
        }

        return $checks->values()->all();
    }

    /** @return list<string> */
    public function keys(): array
    {
        return collect($this->run())->pluck('key')->all();
    }

    private function fromPreflight(PreflightCheck $check): MonitorCheck
    {
        if ($check->id === 'scheduler') {
            return new MonitorCheck('windows_task_scheduler_manual', MonitorCheck::INFO, 'Windows Task Scheduler vyžaduje samostatnou ruční kontrolu.', ['manual_check' => true], false);
        }
        if ($check->id === 'connector_runtime_debt') {
            return new MonitorCheck('connector_runtime_debt', MonitorCheck::WARN, $check->message, ['accepted_risk' => true], false);
        }
        $severity = match ($check->status) {
            PreflightCheck::OK => MonitorCheck::OK,
            PreflightCheck::WARN => MonitorCheck::WARN,
            default => in_array($check->id, self::CRITICAL_PREFLIGHT_KEYS, true) ? MonitorCheck::CRITICAL : MonitorCheck::ERROR,
        };

        return new MonitorCheck($check->id, $severity, $check->message, $this->safeContext->sanitize($check->context));
    }

    private function schedulerHeartbeat(): MonitorCheck
    {
        if (! Schema::hasTable('scheduler_heartbeats')) {
            return new MonitorCheck('scheduler_heartbeat', MonitorCheck::ERROR, 'Persistentní scheduler heartbeat není dostupný.', ['table_available' => false]);
        }
        $heartbeat = SchedulerHeartbeat::query()->find('laravel');
        $limit = max(1, (int) config('operations.monitoring.scheduler_stale_minutes', 10));
        if ($heartbeat === null || $heartbeat->scheduler_seen_at === null) {
            return new MonitorCheck('scheduler_heartbeat', MonitorCheck::WARN, 'Laravel scheduler zatím nezapsal heartbeat.', ['age_minutes' => null, 'limit_minutes' => $limit]);
        }
        $age = max(0, (int) $heartbeat->scheduler_seen_at->diffInMinutes(now()));
        if ($age > $limit) {
            return new MonitorCheck('scheduler_heartbeat', MonitorCheck::ERROR, 'Laravel scheduler heartbeat je zastaralý.', ['age_minutes' => $age, 'limit_minutes' => $limit]);
        }
        if (! $heartbeat->monitor_enabled) {
            return new MonitorCheck('scheduler_heartbeat', MonitorCheck::INFO, 'Laravel scheduler běží, monitoring je feature flagem vypnutý.', ['age_minutes' => $age, 'monitor_enabled' => false], false);
        }
        $severity = in_array($heartbeat->last_monitor_status, [MonitorCheck::ERROR, MonitorCheck::CRITICAL], true) ? MonitorCheck::ERROR : MonitorCheck::OK;

        return new MonitorCheck('scheduler_heartbeat', $severity, $severity === MonitorCheck::OK
            ? 'Laravel scheduler i monitoring jsou aktivní.'
            : 'Scheduler běží, poslední monitorovací běh však obsahoval chybu.', [
                'age_minutes' => $age,
                'monitor_enabled' => true,
                'last_monitor_status' => $heartbeat->last_monitor_status,
            ]);
    }

    private function monitorFreshness(?int $currentRunId): MonitorCheck
    {
        if (! Schema::hasTable('monitor_runs')) {
            return new MonitorCheck('monitor_last_success', MonitorCheck::ERROR, 'Historie monitorovacích běhů není dostupná.', ['table_available' => false]);
        }
        $query = MonitorRun::query()->whereNotNull('completed_at')->whereIn('status', [MonitorCheck::OK, MonitorCheck::INFO, MonitorCheck::WARN]);
        if ($currentRunId !== null) {
            $query->whereKeyNot($currentRunId);
        }
        $run = $query->latest('completed_at')->first();
        $limit = max(1, (int) config('operations.monitoring.run_stale_minutes', 15));
        if ($run === null) {
            return new MonitorCheck('monitor_last_success', MonitorCheck::WARN, 'Dosud neexistuje dokončený úspěšný monitorovací běh.', ['age_minutes' => null, 'limit_minutes' => $limit]);
        }
        $age = max(0, (int) $run->completed_at->diffInMinutes(now()));
        $severity = $age > $limit ? MonitorCheck::ERROR : MonitorCheck::OK;

        return new MonitorCheck('monitor_last_success', $severity, $severity === MonitorCheck::OK
            ? 'Poslední úspěšný monitorovací běh je čerstvý.'
            : 'Poslední úspěšný monitorovací běh je zastaralý.', ['age_minutes' => $age, 'limit_minutes' => $limit]);
    }

    private function monitorOverlap(?int $currentRunId): MonitorCheck
    {
        if (! Schema::hasTable('monitor_runs')) {
            return new MonitorCheck('monitor_overlap', MonitorCheck::ERROR, 'Překrytí monitorovacích běhů nelze ověřit.', ['table_available' => false]);
        }
        $cutoff = now()->subSeconds(max(1, (int) config('operations.monitoring.lock_seconds', 240)));
        $query = MonitorRun::query()->where('status', 'RUNNING')->where('started_at', '<', $cutoff);
        if ($currentRunId !== null) {
            $query->whereKeyNot($currentRunId);
        }
        $stale = $query->count();

        return new MonitorCheck('monitor_overlap', $stale > 0 ? MonitorCheck::ERROR : MonitorCheck::OK, $stale > 0
            ? 'Existuje zastaralý nedokončený monitorovací běh.'
            : 'Nebyl zjištěn překrytý ani zastaralý monitorovací běh.', ['stale_run_count' => $stale]);
    }

    private function integrationDelivery(): MonitorCheck
    {
        $staleMinutes = max(1, (int) config('operations.monitoring.outbox_lock_stale_minutes', 15));
        $outboxRetries = 0;
        $staleLocks = 0;
        $deadLetters = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        if (Schema::hasTable('outbox_events')) {
            $outboxRetries = (int) OutboxEvent::query()->where('attempts', '>', 0)->sum('attempts');
            $staleLocks = OutboxEvent::query()->where('status', OutboxEvent::PROCESSING)
                ->where('locked_at', '<', now()->subMinutes($staleMinutes))->count();
            $deadLetters += OutboxEvent::query()->where('status', OutboxEvent::FAILED)->count();
        }
        $integrationRetries = Schema::hasTable('integration_events') ? (int) DB::table('integration_events')->where('attempts', '>', 0)->sum('attempts') : 0;
        $integrationFailed = Schema::hasTable('integration_events') ? DB::table('integration_events')->where('status', 'FAILED')->count() : 0;
        $deadLetters += $integrationFailed;
        $retries = $outboxRetries + $integrationRetries;
        $severity = $deadLetters > 0 || $staleLocks > 0 ? MonitorCheck::ERROR : ($retries > 0 ? MonitorCheck::WARN : MonitorCheck::OK);

        return new MonitorCheck('integration_delivery', $severity, $severity === MonitorCheck::OK
            ? 'Integrační doručování nemá retry, stale lock ani dead-letter stav.'
            : 'Integrační doručování vyžaduje provozní kontrolu.', [
                'retry_count' => $retries,
                'stale_lock_count' => $staleLocks,
                'dead_letter_count' => $deadLetters,
                'stale_limit_minutes' => $staleMinutes,
            ]);
    }

    private function tlsCertificate(): MonitorCheck
    {
        if (! config('operations.monitoring.tls_enabled')) {
            return new MonitorCheck('tls_certificate', MonitorCheck::INFO, 'Automatická kontrola TLS certifikátu je vypnutá.', ['enabled' => false], false);
        }
        $host = (string) config('operations.monitoring.tls_host');
        if ($host === '' || preg_match('/\A[a-z0-9.-]+\z/i', $host) !== 1) {
            return new MonitorCheck('tls_certificate', MonitorCheck::ERROR, 'TLS kontrola nemá bezpečně nakonfigurovaný hostname.', ['configured' => false]);
        }
        $result = $this->tls->inspect(
            $host,
            max(1, (int) config('operations.monitoring.tls_port', 443)),
            max(1, (int) config('operations.monitoring.tls_timeout_seconds', 5)),
        );
        if (! $result['ok']) {
            return new MonitorCheck('tls_certificate', MonitorCheck::ERROR, 'TLS certifikát nelze bezpečně ověřit.', ['result_code' => $result['code']]);
        }
        $days = $result['days_remaining'];
        $errorDays = max(0, (int) config('operations.monitoring.tls_error_days', 7));
        $warnDays = max($errorDays, (int) config('operations.monitoring.tls_warn_days', 30));
        $severity = $days <= $errorDays ? MonitorCheck::ERROR : ($days <= $warnDays ? MonitorCheck::WARN : MonitorCheck::OK);

        return new MonitorCheck('tls_certificate', $severity, $severity === MonitorCheck::OK
            ? 'TLS certifikát má dostatečnou dobu platnosti.'
            : 'TLS certifikát se blíží expiraci.', ['days_remaining' => $days, 'warn_days' => $warnDays, 'error_days' => $errorDays]);
    }

    private function protect(string $key, callable $check): MonitorCheck
    {
        try {
            return $check();
        } catch (Throwable) {
            return new MonitorCheck($key, MonitorCheck::ERROR, 'Kontrolu nelze bezpečně dokončit; interní detail nebyl zveřejněn.', ['check_failed' => true]);
        }
    }
}
