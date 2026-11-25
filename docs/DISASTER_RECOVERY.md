# Disaster recovery

## Rozhodovací rámec

1. Zastavte nové zápisy pouze schváleným incident postupem; tato Fáze žádný kill switch ani automatickou změnu produkce nezavádí.
2. Pokud je zdroj dostupný, vytvořte nový balíček přes `backup:create` v řízeném maintenance okně. Jinak určete poslední skutečný produkční `COMPLETE` manifest, ověřte SHA-256, autentizovaný `recovery-evidence.json`, dostupnost off-server kopie a secret escrow. Chybějící sidecar nezneplatní samotnou zálohu, ale znamená chybějící auditní důkaz.
3. Pokud poslední sada neprojde `backup:verify`, nepoužívejte ji a eskalujte na předchozí ověřenou sadu.
4. Nejprve proveďte [izolovaný restore](RESTORE_RUNBOOK.md). Přepnutí služby je samostatné vlastnické rozhodnutí mimo tento automatizovaný nástroj.
5. Po obnově zachovejte auditní stopu: backup ID, Git revizi, verze runtime, časy, výsledky kontrol a schválení bez PII/tajemství. Úspěšný `backup:restore-check --evidence-id=...` doplní append-only historii do sidecaru; původní manifest ani komponenty neměňte.

## Závislosti obnovy

Obnova potřebuje Git revizi/tag, Composer lock, podporovaný portálový PHP/MariaDB runtime, Apache/PHP konfiguraci, cron export, private storage, oddělené `.env`/APP_KEY escrow a HPSERVER konfiguraci/state. Portál nemá SQL připojení do Pohody; connector zůstává read-only a ceny se přes bridge nezapisují. PHP 7.4.33 a Windows Server 2012 R2 jsou přijaté riziko a tato fáze je nemění.

## Cíle a cvičení

Vlastník dne 25. 11. 2026 výslovně potvrdil **RPO = 24 hodin** a **RTO = 8 hodin**. Při incidentu se vůči těmto hodnotám eviduje čas posledního použitelného konzistenčního bodu a doba od vyhlášení obnovy po ověřenou provozuschopnost. Doporučený čtvrtletní test zůstává povinnou kontrolou dosažitelnosti, nikoli náhradou měření skutečného incidentu.

Fáze 8B se uzavře teprve po úplné off-server kopii aktuální zálohy, její checksum kontrole a zbývajících provozních měřeních. Automatické mazání zůstává vypnuté. Plánovaný backup ani monitoring samy off-server kopii nepotvrzují a restore neprovádějí; Fáze 8C zůstává produkčně neaktivovaná.
