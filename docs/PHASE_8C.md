# Fáze 8C – produkční monitoring, health checks a alerty

Datum implementace: 24. 11. 2026. Kód, migrace, CLI, scheduler registrace, admin UI, veřejný health endpoint a testy jsou připravené. Fáze 8C **není produkčně dokončená ani aktivovaná**.

## Rozsah implementace

- persistentní tabulky `monitor_runs`, `operational_alerts`, `operational_alert_events` a `scheduler_heartbeats`;
- opakované kontroly nad existujícím preflightem a samostatné scheduler, monitor-run, delivery, private-storage a TLS kontroly;
- `ops:monitor [--json] [--no-notify] [--check=<key>]` s globálním lockem;
- deduplikovaný lifecycle `OPEN → ACKNOWLEDGED → RESOLVED`, nový cyklus po návratu problému a append-only historie;
- strukturovaný aplikační log, AK_ADMIN UI fronta a volitelný e-mail za samostatným vypnutým flagem;
- scheduler heartbeat každou minutu a monitor každých pět minut pouze při `OPS_MONITOR_ENABLED=true`;
- minimální rate-limitovaný `GET /health` vracející jen `OK` nebo `UNAVAILABLE`;
- CZ/PL administrativní texty a bezpečná 404 pro ostatní role.

Monitoring nemění obchodní workflow, integrační payloady, Pohodu, SQL Server ani HPSERVER. Neobsahuje žádný endpoint pro import, migraci, backup/restore, bridge command, změnu `.env` nebo restart služby.

## Výchozí stav

`OPS_MONITOR_ENABLED=false`, `OPS_ALERT_EMAIL_ENABLED=false` a `OPS_TLS_CHECK_ENABLED=false`. Příjemci nejsou v repozitáři. Registrace scheduleru sama monitoring neaktivuje a `onOneServer` se nepoužívá.

PHP 7.4.33 a Windows Server 2012 R2 jsou nealertující dokumentovaný `WARN`. Stav Windows Task Scheduleru zůstává ruční kontrolou; automaticky se hodnotí pouze důsledky podle bridge heartbeatů.

## Akceptační stav

Automatizované testy používají pouze `APP_ENV=testing` a SQLite `:memory:`. Produkčně nebyla spuštěna migrace, změněna `.env`, aktivován scheduler ani kontaktováno SMTP. Před dokončením 8C zbývá checklist v [MONITORING.md](MONITORING.md): řízená migrace, aktivace flagu, několik intervalů, bezpečný test deduplikace/recovery, ruční UI kontrola, potvrzení kanálu a preflight bez neočekávaného `ERROR`.

Fáze 8B zůstává otevřená kvůli chybějícímu autentizovanému restore `SUCCESS` a zastaralé poslední záloze; viz [PHASE_8B.md](PHASE_8B.md).
