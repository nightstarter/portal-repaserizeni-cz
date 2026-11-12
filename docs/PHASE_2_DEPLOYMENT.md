# Fáze 2 – audit pending migrací a plán nasazení

Audit byl proveden 12. listopadu 2026 pouze čtecími příkazy. Produkční migrace nebyla spuštěna a produkční data nebyla změněna.

## Výchozí stav

- Portálová databáze: MariaDB `10.11.14-MariaDB-0ubuntu0.24.04.1-log`, databáze `portal_repaserizeni`, driver `mysql`.
- Poslední produkční batch je `2`; migrace `2026_11_12_000001` až `2026_11_12_000004` jsou provedené.
- Čeká právě jedna migrace: `2026_11_12_000005_add_phase_2c_domain_guards`.
- `process_threads` i `items` používají InnoDB. V okamžiku auditu měly obě tabulky 0 řádků.
- Cílové sloupce ani indexy dosud neexistují. Nebyly nalezeny žádné duplicitní skupiny `(order_id, type)` ani `(order_id, role)`.

Stav byl zjištěn pomocí `php8.4 artisan migrate:status`, dotazů na `VERSION()`, `DATABASE()`, `information_schema` a agregačních dotazů nad cílovými tabulkami. Příkaz `php8.4 artisan migrate --pretend` pouze vygeneroval SQL; nic neprovedl.

## Pending migrace

| Migrace | Tabulka | Změna | Index / FK | Rozšiřující | Očekávané zamykání | Kompatibilita | Rollback |
|---|---|---|---|---|---|---|---|
| `2026_11_12_000005_add_phase_2c_domain_guards` | `process_threads` | `type_instance INT UNSIGNED NOT NULL DEFAULT 1` za `type` | UNIQUE `(order_id, type, type_instance)`; žádný nový FK | Ano, `up()` nic nemaže ani nepřejmenovává | Přidání sloupce je na MariaDB 10.11 způsobilé pro instant DDL. Sestavení unikátního indexu tabulku skenuje a DDL může krátce čekat na exkluzivní metadata lock na začátku a konci. | Ano pro zjištěnou MariaDB 10.11.14/InnoDB | Nejprve odstraní unikátní index, potom sloupec. Po zápisu instancí vyšších než 1 je rollback ztrátový a musí se nahradit obnovou zálohy. |
| `2026_11_12_000005_add_phase_2c_domain_guards` | `items` | `role_instance INT UNSIGNED NOT NULL DEFAULT 1` za `role` | UNIQUE `(order_id, role, role_instance)`; žádný nový FK | Ano, `up()` nic nemaže ani nepřejmenovává | Stejné vlastnosti jako výše; velikost a dlouhé transakce je nutné znovu ověřit těsně před nasazením. | Ano pro zjištěnou MariaDB 10.11.14/InnoDB | Nejprve odstraní unikátní index, potom sloupec; platí stejná podmínka bezpečného rollbacku. |

Laravel pro aktuální databázi vygeneroval:

```sql
alter table `process_threads` add `type_instance` int unsigned not null default '1' after `type`;
alter table `process_threads` add unique `process_threads_order_id_type_type_instance_unique`(`order_id`, `type`, `type_instance`);
alter table `items` add `role_instance` int unsigned not null default '1' after `role`;
alter table `items` add unique `items_order_id_role_role_instance_unique`(`order_id`, `role`, `role_instance`);
```

Výchozí hodnota `1` zachová význam existujících singleton větví a kusů. Unikátní klíče brání dvojímu vytvoření stejné instance procesní větve i fyzického kusu při souběhu. Vyšší číslo instance umožní budoucí opakovatelný proces bez oslabení této ochrany.

Migrace neobsahuje `UPDATE`, `DELETE`, `DROP` ani `IGNORE`. Pokud by se před nasazením objevily duplicitní hodnoty, vytvoření unikátního indexu skončí chybou; data se nemají tiše odstranit ani přepsat. MariaDB dokumentuje jak chování unikátního indexu při duplicitách, tak metadata locky a online/instant ALTER TABLE v [ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table), [InnoDB Online DDL Overview](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-overview) a [Instant ADD COLUMN](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-operations-with-the-instant-alter-algorithm).

## Pořadí nasazení

1. Vyhlásit krátké servisní okno a zastavit případné webové zápisy. Import, scheduler a Pohoda bridge ponechat vypnuté.
2. Pořídit a ověřit obnovitelnou databázovou zálohu.
3. Znovu spustit `php8.4 artisan migrate:status`, ověřit verzi DB, engine, počty řádků, duplicity cílových klíčů a dlouhé transakce/metadata locky.
4. Ověřit deployment konfiguraci: zejména `APP_ENV=production`, `APP_DEBUG=false`, `LEGACY_SHOP_IMPORT_ENABLED=false`, `LEGACY_SHOP_MAPPING_VERIFIED=true` a `POHODA_BRIDGE_ENABLED=false`. Při auditu měl host bezpečné `APP_DEBUG=false`, ale `APP_ENV=local`; před otevřením provozu je nutné tuto hodnotu napravit v deployment secretu.
5. Nasadit přesně schválený commit a instalovat závislosti z lock souboru bez vývojových balíčků.
6. Teprve v servisním okně spustit `php8.4 artisan migrate --force`.
7. Ověřit, že migrace je `Ran`, oba sloupce mají default `1`, oba unikátní indexy existují a aplikace zapisuje pouze očekávané instance.
8. Vyčistit a znovu sestavit framework cache podle provozního postupu; případné workery restartovat až v jejich budoucím nasazení.
9. Provést smoke-test plán z `docs/PHASE_2_ACCEPTANCE.md` a sledovat aplikační i DB logy.
10. Import a scheduler ponechat vypnuté i po ověření Fáze 2.

## Rollback

Před prvním produkčním workflow zápisem lze použít `php8.4 artisan migrate:rollback --step=1`, následně ověřit odstranění pouze nových indexů a sloupců. Jakmile aplikace použije `type_instance` nebo `role_instance` vyšší než `1`, automatický `down()` by tyto hodnoty odstranil; v takové situaci se rollback migrace nesmí spustit a návrat vyžaduje zastavení zápisů a obnovu ověřené zálohy nebo předem připravenou datově zachovávající nápravnou migraci.
