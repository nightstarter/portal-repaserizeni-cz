# Produkční monitoring

## Check keys a výchozí prahy

| Check key | Zdroj / práh | Alert |
|---|---|---|
| `application_environment` | production + debug off | CRITICAL při odchylce |
| `https_and_session` | HTTPS URL, Secure/HttpOnly/SameSite | CRITICAL |
| `database` | read-only `SELECT 1` | CRITICAL |
| `migrations` | žádná pending migrace | ERROR |
| `storage_framework`, `storage_logs`, `bootstrap_cache` | dostupné a zapisovatelné | CRITICAL |
| `private_storage` | private disk mimo public | CRITICAL |
| `cache` | dostupný backend bez testovacího zápisu | ERROR |
| `scheduler_heartbeat` | výchozí stáří 10 minut | WARN bez prvního heartbeat, ERROR stale |
| `monitor_last_success` | výchozí stáří 15 minut | WARN bez prvního runu, ERROR stale |
| `monitor_overlap` | run starší než lock 240 s | ERROR; lock contention je CRITICAL |
| `pohoda_heartbeat` | 15 minut | WARN |
| `pohoda_snapshot` | 30 minut | WARN; známý PARTIAL 2482 + 2 duplicity je OK |
| `bridge_runs` | receiving/failed runy | WARN/ERROR |
| `integration_outbox` | pending/failed | WARN/ERROR |
| `integration_delivery` | retry, stale lock 15 min, dead-letter | WARN/ERROR |
| `latest_backup` | COMPLETE + checksum + 26 hodin | ERROR |
| `recovery_evidence` | validní sidecar + restore do 100 dní | WARN/ERROR |
| `disk_space` | nejméně 1024 MB | CRITICAL |
| `private_storage_inventory` | agregovaný orphan/missing počet 0 | ERROR |
| `feature_flags`, `bridge_command_whitelist`, `bridge_server_allowlists` | bezpečné produkční hodnoty | ERROR |
| `tls_certificate` | volitelné; WARN 30 dní, ERROR 7 dní | bez vypnutí peer/hostname verify |
| `connector_runtime_debt` | PHP 7.4.33 / Windows Server 2012 R2 | nealertující WARN |
| `windows_task_scheduler_manual` | pouze ruční stav | nealertující INFO |

Kontroly nevracejí cesty, názvy souborů, host/DB identity, credentials, DSN, secrets, PII ani obsah dokumentů. Private storage používá pouze agregované počty.

## CLI

```bash
php8.5 artisan ops:monitor
php8.5 artisan ops:monitor --json --no-notify
php8.5 artisan ops:monitor --check=latest_backup --json --no-notify
```

Exit code je 0 pro `OK`, `INFO` a `WARN`; `ERROR` nebo `CRITICAL` vrací nenulový exit. `--no-notify` zapisuje run, alerty a lokální strukturovaný log, ale nekontaktuje e-mail. Neznámý přesný klíč bezpečně selže. Jedna chyba checku nezastaví ostatní.

## Scheduler heartbeat význam

- chybějící/stale `scheduler_seen_at`: Laravel cron neběží nebo nedokončuje heartbeat;
- heartbeat čerstvý + `monitor_enabled=false`: scheduler běží, monitor je vypnutý;
- heartbeat čerstvý + poslední run bez ERROR/CRITICAL: monitor běží a dokončil se;
- heartbeat čerstvý + chybový poslední run: scheduler běží, některé kontroly selhaly.

Windows Task Scheduler konektoru se z tohoto heartbeat neodvozuje.

## Budoucí aktivace

1. Nasadit přesný ověřený commit a provést zálohu.
2. Spustit migraci `2026_11_24_000017_create_phase_8c_monitoring.php` v řízeném okně.
3. Ponechat `OPS_MONITOR_ENABLED=false`; obnovit config cache.
4. Spustit jednorázově `ops:monitor --json --no-notify` a vyhodnotit všechny check keys.
5. Ručně ověřit `/health` a AK_ADMIN UI bez informačního úniku.
6. Ověřit existující cron `schedule:run`; nový cron nevytvářet bez schválení.
7. Nastavit `OPS_MONITOR_ENABLED=true`, obnovit config cache a sledovat nejméně tři pětiminutové intervaly.
8. Bez SMTP vyvolat schválený bezpečný test alert, ověřit deduplikaci, ACK, recovery a audit.
9. Samostatně schválit alespoň jeden alert kanál. E-mail zapínat až po příjemcích a SMTP testu.

Rollback: nejprve `OPS_MONITOR_ENABLED=false` a config cache; databázové tabulky nemažte, historii zachovejte. Kód lze vrátit na předchozí tag. Migraci nevracejte automaticky.
