<?php

namespace App\Operations;

use App\Models\BridgeHeartbeat;
use App\Models\OutboxEvent;
use App\Models\StockSnapshotConfirmation;
use App\Models\StockSnapshotItem;
use App\Models\StockSnapshotRun;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionPreflight
{
    public function __construct(
        private readonly BackupEvidenceHealth $backupEvidenceHealth,
    ) {}

    /** @return list<PreflightCheck> */
    public function run(): array
    {
        $checks = [];
        $checks[] = $this->environmentCheck();
        $checks[] = $this->httpsCheck();
        $checks[] = $this->databaseCheck();
        $checks[] = $this->protect('migrations', fn (): PreflightCheck => $this->migrationCheck());
        array_push($checks, ...$this->runtimeDirectoryChecks());
        $checks[] = $this->privateStorageCheck();
        $checks[] = $this->cacheCheck();
        $checks[] = new PreflightCheck(
            'scheduler', PreflightCheck::WARN,
            'Stav Windows Task Scheduleru nelze z portálu spolehlivě zjistit; ručně ověřte Last Run Result, čas běhu a nepřekrývání instancí.',
            ['manual_check' => true],
        );
        $checks[] = $this->protect('pohoda_heartbeat', fn (): PreflightCheck => $this->heartbeatCheck());
        $checks[] = $this->protect('pohoda_snapshot', fn (): PreflightCheck => $this->snapshotCheck());
        $checks[] = $this->protect('bridge_runs', fn (): PreflightCheck => $this->bridgeQueueCheck());
        $checks[] = $this->protect('integration_outbox', fn (): PreflightCheck => $this->outboxCheck());
        $checks[] = $this->featureFlagCheck();
        $checks[] = $this->commandWhitelistCheck();
        $checks[] = $this->serverAllowlistCheck();
        $checks[] = $this->smtpCheck();
        $checks[] = $this->diskCheck();
        $checks[] = $this->protect('latest_backup', fn (): PreflightCheck => $this->backupEvidenceCheck('latest_backup', $this->backupEvidenceHealth->latest()));
        $checks[] = $this->protect('recovery_evidence', fn (): PreflightCheck => $this->backupEvidenceCheck('recovery_evidence', $this->backupEvidenceHealth->restoreTest()));
        $checks[] = new PreflightCheck(
            'connector_runtime_debt', PreflightCheck::WARN,
            'Konektor zůstává kompatibilní s přijatým technickým dluhem PHP 7.4.33 / Windows Server 2012 R2; upgrade není součástí Fáze 8A.',
            ['accepted_risk' => true],
        );

        return $checks;
    }

    public function hasErrors(array $checks): bool
    {
        return collect($checks)->contains(fn (PreflightCheck $check): bool => $check->status === PreflightCheck::ERROR);
    }

    private function protect(string $id, callable $callback): PreflightCheck
    {
        try {
            return $callback();
        } catch (Throwable) {
            return new PreflightCheck($id, PreflightCheck::ERROR, 'Kontrolu nelze bezpečně dokončit; interní detail nebyl zveřejněn.');
        }
    }

    private function environmentCheck(): PreflightCheck
    {
        $production = app()->environment('production');
        $debug = (bool) config('app.debug');

        return new PreflightCheck(
            'application_environment',
            $production && ! $debug ? PreflightCheck::OK : PreflightCheck::ERROR,
            $production && ! $debug
                ? 'Aplikace běží v produkčním režimu s vypnutým debugem.'
                : 'Je vyžadováno APP_ENV=production a APP_DEBUG=false.',
            ['production' => $production, 'debug' => $debug],
        );
    }

    private function httpsCheck(): PreflightCheck
    {
        $url = (string) config('app.url');
        $https = strcasecmp((string) parse_url($url, PHP_URL_SCHEME), 'https') === 0;
        $secureCookie = (bool) config('session.secure');
        $httpOnly = (bool) config('session.http_only');
        $sameSite = strtolower((string) config('session.same_site'));
        $safe = $https && $secureCookie && $httpOnly && in_array($sameSite, ['lax', 'strict'], true);

        return new PreflightCheck(
            'https_and_session', $safe ? PreflightCheck::OK : PreflightCheck::ERROR,
            $safe ? 'HTTPS a bezpečné session cookie atributy jsou nakonfigurovány.' : 'HTTPS nebo session cookie flags nejsou bezpečně nakonfigurovány.',
            ['https' => $https, 'secure_cookie' => $secureCookie, 'http_only' => $httpOnly, 'same_site' => $sameSite],
        );
    }

    private function databaseCheck(): PreflightCheck
    {
        try {
            /** @var ConnectionInterface $connection */
            $connection = DB::connection();
            $connection->select('SELECT 1');

            return new PreflightCheck('database', PreflightCheck::OK, 'Databázové spojení odpovědělo na read-only dotaz.');
        } catch (Throwable) {
            return new PreflightCheck('database', PreflightCheck::ERROR, 'Databázové spojení není dostupné.');
        }
    }

    private function migrationCheck(): PreflightCheck
    {
        try {
            $files = collect(glob(database_path('migrations/*.php')) ?: [])
                ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME));
            if (! Schema::hasTable('migrations')) {
                return new PreflightCheck('migrations', PreflightCheck::ERROR, 'Tabulka migrations neexistuje.', ['pending_count' => $files->count()]);
            }
            $applied = DB::table('migrations')->pluck('migration');
            $pending = $files->diff($applied)->values();

            return new PreflightCheck(
                'migrations', $pending->isEmpty() ? PreflightCheck::OK : PreflightCheck::ERROR,
                $pending->isEmpty() ? 'Všechny verzované migrace jsou aplikované.' : 'Existují pending migrace.',
                ['applied_count' => $applied->count(), 'pending_count' => $pending->count(), 'pending' => $pending->all()],
            );
        } catch (Throwable) {
            return new PreflightCheck('migrations', PreflightCheck::ERROR, 'Stav migrací nelze read-only ověřit.');
        }
    }

    /** @return list<PreflightCheck> */
    private function runtimeDirectoryChecks(): array
    {
        $paths = [
            'storage_framework' => storage_path('framework'),
            'storage_logs' => storage_path('logs'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        return collect($paths)->map(function (string $path, string $id): PreflightCheck {
            $ok = is_dir($path) && is_writable($path);

            return new PreflightCheck($id, $ok ? PreflightCheck::OK : PreflightCheck::ERROR, $ok ? 'Runtime adresář existuje a je zapisovatelný.' : 'Runtime adresář chybí nebo není zapisovatelný.');
        })->values()->all();
    }

    private function privateStorageCheck(): PreflightCheck
    {
        $root = (string) config('filesystems.disks.local.root');
        $realPublic = realpath(public_path());
        $realRoot = realpath($root);
        $outsidePublic = $realRoot !== false && ($realPublic === false || ! str_starts_with($realRoot.DIRECTORY_SEPARATOR, $realPublic.DIRECTORY_SEPARATOR));
        $ok = is_dir($root) && $outsidePublic && config('filesystems.disks.local.visibility') === 'private';

        return new PreflightCheck('private_storage', $ok ? PreflightCheck::OK : PreflightCheck::ERROR, $ok ? 'Privátní storage existuje mimo public webroot.' : 'Privátní storage chybí nebo není bezpečně oddělena od webrootu.');
    }

    private function cacheCheck(): PreflightCheck
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");
        try {
            $available = match ($driver) {
                'database' => Schema::hasTable((string) config("cache.stores.{$store}.table", 'cache')),
                'file' => is_dir((string) config("cache.stores.{$store}.path")) && is_writable((string) config("cache.stores.{$store}.path")),
                'array' => true,
                default => false,
            };
        } catch (Throwable) {
            $available = false;
        }

        return new PreflightCheck('cache', $available ? PreflightCheck::OK : PreflightCheck::ERROR, $available ? 'Cache backend je dostupný bez testovacího zápisu.' : 'Cache backend nelze bezpečně ověřit.', ['driver' => $driver]);
    }

    private function heartbeatCheck(): PreflightCheck
    {
        if (! Schema::hasTable('bridge_heartbeats')) {
            return new PreflightCheck('pohoda_heartbeat', PreflightCheck::ERROR, 'Tabulka bridge heartbeatů chybí.');
        }
        $heartbeat = BridgeHeartbeat::query()->latest('occurred_at')->first();
        $limit = max(1, (int) config('operations.preflight.heartbeat_stale_minutes', 15));
        if ($heartbeat === null) {
            return new PreflightCheck('pohoda_heartbeat', PreflightCheck::WARN, 'Pohoda heartbeat zatím není dostupný.', ['age_minutes' => null, 'limit_minutes' => $limit]);
        }
        $age = max(0, (int) $heartbeat->occurred_at->diffInMinutes(now()));
        $ok = $age <= $limit && $heartbeat->status === 'OK';

        return new PreflightCheck('pohoda_heartbeat', $ok ? PreflightCheck::OK : PreflightCheck::WARN, $ok ? 'Pohoda heartbeat je čerstvý.' : 'Pohoda heartbeat je zastaralý nebo degradovaný.', ['occurred_at' => $heartbeat->occurred_at->toIso8601String(), 'age_minutes' => $age, 'limit_minutes' => $limit, 'connector_status' => $heartbeat->status]);
    }

    private function snapshotCheck(): PreflightCheck
    {
        if (! Schema::hasTable('stock_snapshot_runs')) {
            return new PreflightCheck('pohoda_snapshot', PreflightCheck::ERROR, 'Tabulka snapshotů chybí.');
        }
        $run = StockSnapshotRun::query()->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])->latest('source_generated_at')->first();
        if ($run === null) {
            return new PreflightCheck('pohoda_snapshot', PreflightCheck::WARN, 'Použitelný Pohoda snapshot zatím není dostupný.');
        }
        $confirmation = StockSnapshotConfirmation::query()->where('stock_snapshot_run_id', $run->id)->latest('received_at')->first();
        $verifiedAt = $confirmation?->received_at ?? $run->completed_at ?? $run->source_generated_at;
        $limit = max(1, (int) config('operations.preflight.snapshot_stale_minutes', config('integrations.pohoda_bridge.stale_minutes', 30)));
        $age = $verifiedAt === null ? null : max(0, (int) $verifiedAt->diffInMinutes(now()));
        if ($age === null || $age > $limit) {
            return new PreflightCheck('pohoda_snapshot', PreflightCheck::WARN, 'Poslední použitelný Pohoda snapshot nebo confirmation je zastaralý.', ['status' => $run->status, 'age_minutes' => $age, 'limit_minutes' => $limit]);
        }
        $knownPartial = $run->status === StockSnapshotRun::PARTIAL
            && (int) $run->accepted_count === 2482
            && (int) $run->rejected_count === 2
            && $this->knownDuplicateCount($run->id) === 2;
        $status = $run->status === StockSnapshotRun::COMPLETE || $knownPartial ? PreflightCheck::OK : PreflightCheck::WARN;

        return new PreflightCheck('pohoda_snapshot', $status, $knownPartial ? 'Čerstvý známý PARTIAL snapshot 2482 + 2 duplicity je použitelný.' : ($status === PreflightCheck::OK ? 'Použitelný Pohoda snapshot je čerstvý.' : 'PARTIAL snapshot je čerstvý, ale odchylka vyžaduje provozní kontrolu.'), [
            'status' => $run->status,
            'verified_at' => $verifiedAt?->toIso8601String(),
            'verified_via' => $confirmation === null ? 'snapshot' : 'confirmation',
            'last_confirmation_at' => $confirmation?->received_at?->toIso8601String(),
            'age_minutes' => $age,
            'accepted' => (int) $run->accepted_count,
            'rejected' => (int) $run->rejected_count,
            'known_partial' => $knownPartial,
        ]);
    }

    private function knownDuplicateCount(int $runId): int
    {
        return StockSnapshotItem::query()
            ->whereHas('batch', fn ($query) => $query->where('stock_snapshot_run_id', $runId))
            ->where('accepted', false)->where('rejection_code', 'DUPLICATE_PRODUCT_REFERENCE')->count();
    }

    private function bridgeQueueCheck(): PreflightCheck
    {
        $counts = Schema::hasTable('stock_snapshot_runs')
            ? StockSnapshotRun::query()->whereIn('status', [StockSnapshotRun::RECEIVING, StockSnapshotRun::FAILED])->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status')
            : collect();
        $receiving = (int) ($counts[StockSnapshotRun::RECEIVING] ?? 0);
        $failed = (int) ($counts[StockSnapshotRun::FAILED] ?? 0);
        $status = $failed > 0 ? PreflightCheck::ERROR : ($receiving > 0 ? PreflightCheck::WARN : PreflightCheck::OK);

        return new PreflightCheck('bridge_runs', $status, $status === PreflightCheck::OK ? 'Bridge nemá receiving ani failed runy.' : 'Bridge obsahuje receiving nebo failed runy.', ['receiving' => $receiving, 'failed' => $failed]);
    }

    private function outboxCheck(): PreflightCheck
    {
        $outbox = Schema::hasTable('outbox_events') ? OutboxEvent::query()->whereIn('status', [OutboxEvent::PENDING, OutboxEvent::FAILED])->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status') : collect();
        $integration = Schema::hasTable('integration_events') ? DB::table('integration_events')->whereIn('status', ['PENDING', 'FAILED'])->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status') : collect();
        $outboxPending = (int) ($outbox[OutboxEvent::PENDING] ?? 0);
        $outboxFailed = (int) ($outbox[OutboxEvent::FAILED] ?? 0);
        $integrationPending = (int) ($integration['PENDING'] ?? 0);
        $integrationFailed = (int) ($integration['FAILED'] ?? 0);
        $failed = $outboxFailed + $integrationFailed;
        $pending = $outboxPending + $integrationPending;
        $status = $failed > 0 ? PreflightCheck::ERROR : ($pending > 0 ? PreflightCheck::WARN : PreflightCheck::OK);

        return new PreflightCheck('integration_outbox', $status, $status === PreflightCheck::OK ? 'Integrační a outbox fronty jsou bez pending/failed událostí.' : 'Integrační nebo outbox fronta vyžaduje pozornost.', [
            'outbox_pending' => $outboxPending,
            'outbox_failed' => $outboxFailed,
            'integration_pending' => $integrationPending,
            'integration_failed' => $integrationFailed,
            'pending' => $pending,
            'failed' => $failed,
        ]);
    }

    private function featureFlagCheck(): PreflightCheck
    {
        $flags = [
            'legacy_import' => (bool) config('integrations.legacy_shop.enabled'),
            'discussion_email' => (bool) config('discussion_notifications.enabled'),
            'buyback_customer_email' => (bool) config('buyback.customer_email_enabled'),
        ];
        $safe = ! in_array(true, $flags, true) && config('operations.product_repricing') === 'DEFERRED_EXTERNAL_SYSTEM';

        return new PreflightCheck('feature_flags', $safe ? PreflightCheck::OK : PreflightCheck::ERROR, $safe ? 'Rizikové funkce jsou vypnuté a přecenění zůstává v externím systému.' : 'Některá funkce je aktivní nebo PRODUCT_REPRICING není DEFERRED_EXTERNAL_SYSTEM.', $flags + ['product_repricing_deferred' => config('operations.product_repricing') === 'DEFERRED_EXTERNAL_SYSTEM']);
    }

    private function commandWhitelistCheck(): PreflightCheck
    {
        $count = count((array) config('integrations.pohoda_bridge.allowed_command_types', []));

        return new PreflightCheck('bridge_command_whitelist', $count === 0 ? PreflightCheck::OK : PreflightCheck::ERROR, $count === 0 ? 'Produkční bridge command whitelist je prázdný.' : 'Bridge command whitelist musí být prázdný.', ['configured_count' => $count]);
    }

    private function serverAllowlistCheck(): PreflightCheck
    {
        $warehouses = (array) config('integrations.pohoda_bridge.warehouses', []);
        $prefixes = (array) config('integrations.pohoda_bridge.product_prefixes', []);
        $ok = in_array('DECIN', $warehouses, true) && in_array('RR', $prefixes, true);

        return new PreflightCheck('bridge_server_allowlists', $ok ? PreflightCheck::OK : PreflightCheck::ERROR, $ok ? 'Serverové allowlisty obsahují DECIN a RR.' : 'Serverové allowlisty DECIN/RR nejsou kompletní.', ['decin' => in_array('DECIN', $warehouses, true), 'rr' => in_array('RR', $prefixes, true)]);
    }

    private function smtpCheck(): PreflightCheck
    {
        $enabled = (bool) config('discussion_notifications.enabled') || (bool) config('buyback.customer_email_enabled');
        if (! $enabled) {
            return new PreflightCheck('smtp', PreflightCheck::OK, 'E-mailové funkce jsou vypnuté; SMTP spojení nebylo navázáno.', ['feature_enabled' => false]);
        }
        $complete = config('mail.default') === 'smtp'
            && filled(config('mail.mailers.smtp.host'))
            && filled(config('mail.from.address'));

        return new PreflightCheck('smtp', $complete ? PreflightCheck::OK : PreflightCheck::ERROR, $complete ? 'SMTP je konfiguračně připravené; žádný e-mail nebyl odeslán.' : 'E-mailová funkce je zapnutá, ale SMTP konfigurace není úplná.', ['feature_enabled' => true, 'configured' => $complete]);
    }

    private function diskCheck(): PreflightCheck
    {
        $testProcess = ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing';
        $override = $testProcess ? config('operations.preflight.disk_free_bytes_override') : null;
        $free = is_numeric($override) ? (int) $override : @disk_free_space(storage_path());
        $minimum = max(1, (int) config('operations.preflight.minimum_free_disk_megabytes', 1024)) * 1024 * 1024;
        $ok = is_int($free) || is_float($free) ? $free >= $minimum : false;

        return new PreflightCheck('disk_space', $ok ? PreflightCheck::OK : PreflightCheck::ERROR, $ok ? 'Volné místo splňuje nastavený limit.' : 'Volné místo nelze zjistit nebo je pod nastaveným limitem.', ['free_megabytes' => is_numeric($free) ? (int) floor($free / 1024 / 1024) : null, 'minimum_megabytes' => (int) floor($minimum / 1024 / 1024)]);
    }

    /** @param array<string,mixed> $health */
    private function backupEvidenceCheck(string $id, array $health): PreflightCheck
    {
        $status = match ($health['status'] ?? 'ERROR') {
            'OK' => PreflightCheck::OK,
            'WARN' => PreflightCheck::WARN,
            default => PreflightCheck::ERROR,
        };
        $message = match ([$id, $status]) {
            ['latest_backup', PreflightCheck::OK] => 'Poslední produkční COMPLETE záloha je čerstvá, ověřená a off-server potvrzená.',
            ['latest_backup', PreflightCheck::WARN] => 'Poslední záloha je platná, ale chybí její off-server potvrzení.',
            ['latest_backup', PreflightCheck::ERROR] => 'Poslední COMPLETE záloha chybí, je neplatná nebo zastaralá.',
            ['recovery_evidence', PreflightCheck::OK] => 'Samostatná autentizovaná evidence obsahuje čerstvý úspěšný restore test.',
            ['recovery_evidence', PreflightCheck::WARN] => 'Samostatný úspěšný restore test chybí nebo je zastaralý.',
            default => 'Restore evidence nebo její vazba na produkční zálohu je neplatná.',
        };

        return new PreflightCheck($id, $status, $message, $health);
    }
}
