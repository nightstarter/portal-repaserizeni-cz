# Deployment

Kořen repozitáře na tomto serveru je `/var/www/portal.repaserizeni.cz/web`; veřejný DocumentRoot je jeho podadresář `public/`.

## Zjištěné prostředí 11. 11. 2026

| Komponenta | Zjištěný stav | Požadavek projektu |
|---|---|---|
| PHP výchozí CLI | 7.3.33 | nepoužívat |
| PHP 8.2 CLI | 8.2.35, potřebná rozšíření dostupná | nestačí pro Laravel 13 |
| PHP 8.4 CLI | 8.4.27, neúplné moduly | nepoužívat pro tento projekt |
| PHP 8.5 CLI/web | 8.5.12, potřebná rozšíření dostupná | cílová verze |
| Composer | lokální `composer.phar` 2.7.8 funguje s PHP 8.5, není v `PATH` | před produkcí aktualizovat Composer 2 a zpřístupnit jej deployment účtu |
| Apache | 2.4.58 (Ubuntu) | podporováno |
| Databázový klient | MariaDB 10.11.14 | ověřit serverovou verzi a práva nové prázdné DB |

PHP 8.5.12 obsahuje potřebné moduly `curl`, `dom`, `mbstring`, `pdo_mysql`, `pdo_sqlite`, `xml` a `zip`. Composer při poslední kontrole nedokázal přeložit DNS názvy `repo.packagist.org`, `api.github.com` ani `getcomposer.org`.

## Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName portal.repaserizeni.cz
    DocumentRoot /var/www/portal.repaserizeni.cz/web/public

    <Directory /var/www/portal.repaserizeni.cz/web/public>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "SAMEORIGIN"
</VirtualHost>
```

HTTP VirtualHost musí pouze přesměrovat na HTTPS. TLS certifikát, HSTS a cookie policy se ověří před go-live. Nikdy nesmí být DocumentRoot nastaven na kořen repozitáře `web/`; směřuje výhradně do `web/public/`.

## První instalace

1. Vytvořit nový databázový účet pouze pro novou prázdnou databázi portálu.
2. Nasadit konkrétní commit/tag a spustit `composer install --no-dev --classmap-authoritative`.
3. Vytvořit produkční `.env` mimo verzování, nastavit `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://portal.repaserizeni.cz` a nový `APP_KEY`.
4. Ponechat `LEGACY_SHOP_IMPORT_ENABLED=false` a `POHODA_BRIDGE_ENABLED=false`.
5. Spustit `php8.5 artisan migrate --force` a `php8.5 artisan db:seed --force`.
6. Interaktivně spustit `php8.5 artisan portal:create-admin` pod důvěryhodným deployment uživatelem.
7. Nastavit zápis pouze pro `storage/` a `bootstrap/cache/` nutnému systémovému uživateli.
8. Spustit `php8.5 artisan config:cache`, `route:cache` a `view:cache`.
9. Ověřit HTTPS `/up`, přihlášení, odhlášení, deaktivovaný účet, CZ/PL a zákaz přístupu POLSKO do správy uživatelů.

## Opakovatelný deployment

1. Ověřit zelené CI a připravit zálohu databáze a privátních souborů.
2. Podle rozsahu zapnout maintenance režim.
3. Stáhnout konkrétní commit/tag.
4. Spustit Composer bez dev závislostí a s optimalizací.
5. Spustit migrace s `--force`.
6. Obnovit cache konfigurace, routes a views.
7. Restartovat workery nebo je ukončit přes `queue:restart`.
8. Provést smoke test a vypnout maintenance režim.

## Cron

```cron
* * * * * php8.5 /var/www/portal.repaserizeni.cz/web/artisan schedule:run
* * * * * php8.5 /var/www/portal.repaserizeni.cz/web/artisan queue:work --stop-when-empty
```

Ve Fázi 0 scheduler neobsahuje import objednávek.

## Rollback

- Vrátit aplikaci na předchozí ověřený tag a znovu sestavit cache.
- Databázové migrace nevracet automaticky, pokud by došlo ke ztrátě dat. Použít předem ověřený downgrade postup konkrétní migrace nebo obnovu zálohy.
- Při incidentu ponechat importní integrační přepínače vypnuté.
- Ověřit `/up` a přihlášení po návratu.
