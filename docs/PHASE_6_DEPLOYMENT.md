# Fáze 6 – deployment reklamací a výkupu

Tento postup je runbook, nikoli záznam provedeného nasazení. Během akceptace nebyl proveden push, pull, produkční migrate, změna `.env`, cronu ani private storage.

## Předpoklady a záloha

1. Naplánovat servisní okno a potvrdit poslední commit určený k nasazení.
2. Zastavit zápisové provozní kroky aplikace; scheduler může zůstat aktivní jen pokud jsou oba e-mailové flagy vypnuté.
3. Vytvořit konzistentní databázovou zálohu MariaDB 10.11 a ověřit, že ji lze načíst do odděleného prostředí.
4. Vytvořit samostatnou zálohu private document storage se zachováním vlastníka, práv a kontrolních součtů. Nezahrnovat ji do `public/`.
5. Zaznamenat aktuální release hash, `php8.5 artisan migrate:status`, `php8.5 artisan route:list` a stav scheduleru.

## Bezpečné výchozí proměnné

Před nasazením musí produkční secret obsahovat minimálně:

```dotenv
APP_ENV=production
APP_DEBUG=false
DISCUSSION_EMAIL_ENABLED=false
BUYBACK_CUSTOMER_EMAIL_ENABLED=false
LEGACY_SHOP_IMPORT_ENABLED=false
POHODA_BRIDGE_ENABLED=false
```

Produkční SMTP credentials se nikdy neukládají do repozitáře, logu ani dokumentace. `.env` se při pull nepřepisuje.

## Kód a závislosti

Na vývojové straně po schválení změn:

```bash
git push origin main
```

Na serveru z kořene repozitáře:

```bash
cd /var/www/portal.repaserizeni.cz/web
git fetch --prune origin
git pull --ff-only origin main
php8.5 composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Pokud server používá ověřenou systémovou binárku Composeru, lze místo `php8.5 composer.phar` použít její explicitní cestu. Nepoužívat neověřený globální Composer.

## Migrace

Nejprve read-only zkontrolovat pořadí:

```bash
php8.5 artisan migrate:status
```

Očekávané pending migrace v pořadí:

1. `2026_11_19_000012_add_phase_6a_warranty_threads`
2. `2026_11_19_000013_create_phase_6c_buybacks`

Po potvrzení záloh aplikovat migrace standardním migratorem:

```bash
php8.5 artisan migrate --force
php8.5 artisan migrate:status
```

Nepoužívat `migrate:fresh`, ruční opakování `up()`, `migrate:rollback` naslepo ani ruční mazání sloupců. Očekává se pouze rozšíření schématu bez mazání nebo přepisu existujících dat.

## Cache, routy a scheduler

```bash
php8.5 artisan optimize:clear
php8.5 artisan config:cache
php8.5 artisan route:cache
php8.5 artisan view:cache
php8.5 artisan route:list
php8.5 artisan schedule:list
```

Ověřit jediný existující worker command:

```bash
php8.5 artisan notifications:dispatch --limit=1
```

Při obou e-mailových flazích `false` nesmí vzniknout SMTP spojení ani zmizet pending event. Cron zůstává jediný:

```cron
* * * * * cd /var/www/portal.repaserizeni.cz/web && php8.5 artisan schedule:run >> /dev/null 2>&1
```

Scheduler registruje `notifications:dispatch` každou minutu s `withoutOverlapping(10)`; `onOneServer` se nepoužívá.

## Smoke test s vypnutými e-maily

1. Ověřit login a role AK_ADMIN, AK_OPERATOR, Polsko a READ_ONLY.
2. Otevřít prázdné stavy `/warranties` a `/buybacks` bez zakládání falešných produkčních dat.
3. Ověřit dashboard/menu, CZ/PL lokalizaci a bezpečnou 404 na nepřístupné známé testovací URL bez zákaznických údajů.
4. Ověřit `/admin/notifications`: oba typy musí jasně ukazovat vypnuté doručování a maskovat příjemce.
5. Ověřit `migrate:status`, `route:list`, `schedule:list` a log scheduleru.
6. Nezapínat e-maily, dokud nejsou schválena oprávnění, mailbox a browser checklist z `PHASE_6_ACCEPTANCE.md`.

## Kontrolované zapnutí

Diskusní a zákaznický e-mail se zapínají odděleně. Nejdřív použít schválený testovací mailbox bez reálných zákaznických dat, nastavit pouze jeden flag na `true`, spustit `php8.5 artisan config:cache`, vytvořit jediný schválený event a ověřit worker i administraci. Teprve po vyhodnocení lze povolit druhý typ.

U zákaznické nabídky před workerem ověřit `BUYBACK_CUSTOMER_OFFER_READY` a pending outbox; po úspěchu musí společně vzniknout outbox `SENT`, systémový delivery event a `BUYBACK_OFFER_SENT`. Při SMTP chybě musí workflow zůstat READY.

## Rollback aplikace

1. Okamžitě nastavit oba e-mailové flagy na `false` a rebuildnout config cache.
2. Vrátit aplikační release na předchozí ověřený commit standardním release mechanismem nebo `git pull --ff-only` na schválenou opravnou větev; nemazat data.
3. Spustit `optimize:clear`, poté `config:cache`, `route:cache` a `view:cache` pro vrácený release.
4. Ověřit health, login, dashboard, scheduler a administraci outboxu.
5. **Neprovádět slepý rollback migrací 000013/000012.** Starší aplikace musí tolerovat přidané tabulky/sloupce. Schema rollback je samostatná změna vyžadující posouzení existujících WARRANTY/BUYBACK dat, zálohu a explicitní plán migrace dat.
6. Pending outbox eventy nemažte; po opravě a opětovném zapnutí se bezpečně zpracují.

## Po nasazení

Zapsat release hash, výsledek obou `migrate:status`, použitou zálohu, výsledek smoke testu, stav flagů a případný jediný kontrolovaný e-mail. Nikdy nezapisovat SMTP heslo, plný zákaznický e-mail ani interní výjimku.
