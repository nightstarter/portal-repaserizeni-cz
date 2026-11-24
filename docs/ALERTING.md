# Alerting

## Lifecycle a deduplikace

Jeden aktivní alert je databázově unikátní pro `check_key + fingerprint`. Opakování zvyšuje `occurrence_count` a zapisuje append-only událost. Změna fingerprintu vyřeší předchozí aktivní cyklus a otevře nový. `OK/INFO` vytvoří jedinou `RESOLVED` událost; další OK nic neduplikuje. Nový výskyt po vyřešení založí nový alert.

Ruční ACK vyžaduje AK_ADMIN AUTOKLUBY, CSRF, povinnou bezpečnou poznámku a shodu `row_version`. ACK nemění technický stav kontroly a nebrání další eskalaci. Události nelze přes aplikační model měnit ani mazat.

Výchozí eskalace: třetí opakování WARN zvýší aktivní alert na ERROR; ERROR trvající 30 minut se při dalším výskytu zvýší na CRITICAL. PHP/Windows technický dluh není alertable.

## Routing

1. Strukturovaný aplikační log bez summary/metadata, PII a secrets.
2. Persistentní AK_ADMIN fronta `/admin/operations/monitoring`.
3. Volitelný e-mail pouze při `OPS_ALERT_EMAIL_ENABLED=true` a neprázdném runtime seznamu příjemců.

Cooldown je výchozích 30 minut. OPENED, ESCALATED a jediné RESOLVED oznámení jsou kandidáti; opakovaný ERROR/CRITICAL nejvýše po cooldownu. ACK neumlčí CRITICAL navždy. SMTP chyba se loguje jen stabilním kódem `ALERT_EMAIL_DELIVERY_FAILED`.

Doručení je at-least-once: stav doručení se zapisuje až po úspěšném předání maileru. Pád mezi předáním a zápisem může vzácně způsobit duplicitu; příjemce musí zprávy deduplikovat podle alert/check/event kontextu.

## Bezpečný test bez SMTP

Použijte výhradně testovací prostředí a `Mail::fake`, nebo produkční `ops:monitor --no-notify`. Neměňte produkční checky umělými daty a nezapínejte SMTP jen kvůli akceptaci. Příjemce se nikdy necommituje.
