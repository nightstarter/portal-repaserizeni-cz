# Fáze 4A — CORE_RETURN a dodavatelské finance

Fáze 4A zavádí doménu vratného dílu od příjmu AUTOKLUBY po polské finanční rozhodnutí. Nemění globální stav objednávky a nepřidává UI, dokumenty, e-mail, ostrý import ani scheduler. Produkční migrace nebyla v rámci implementace spuštěna.

## Rozsah

- samostatný `CORE_RETURN` automat a neměnná historie kontrol;
- přeprava `CORE_RETURN` výhradně `AK_TO_PL` přes existující `ShipmentService`;
- samostatný dodavatelský ledger s desetinnými částkami a ISO měnou;
- plné, krácené a explicitně bez-zálohové vypořádání;
- row-level viditelnost, role, audit, idempotence, optimistic lock a row lock;
- odvozené uzavření objednávky až po dokončení všech povinných větví.

## Bezpečnostní brány

Před zahájením byl `HEAD` i `origin/main` na commitu `84fe126`, pracovní strom čistý a produkční `.env` měl `APP_ENV=production`. `.env.testing` a `phpunit.xml` používají pouze `sqlite` / `:memory:`. `DatabaseCommandGuard` v produkci blokuje `migrate:fresh`, `migrate:refresh`, `migrate:reset` a `db:wipe` i s `--force`.

Nové schéma je popsáno migrací `2026_11_18_000007_create_phase_4a_core_returns.php`. Je určena pro pozdější řízené nasazení; během Fáze 4A nebyla aplikována na produkční databázi.

## Mimo rozsah

- refundace zákaznické zálohy v e-shopu;
- faktury, dobropisy, uploady, `CREDIT_ISSUED` a `CREDIT_PAID` (Fáze 4B);
- UI a e-mailové notifikace;
- ostré importy, integrace dokladů a scheduler.

## Ověření

Doménové testy používají `RefreshDatabase` nad SQLite `:memory:`. Pokrývají příjem a všechny příznaky kontroly, jiný kus bez změny produktu, GLS i svoz, dávku více vratek, polský příjem, všechny finanční varianty a chyby, oprávnění, admin override, idempotenci, souběžné verdikty, rollback i uzavření objednávky. Celý `composer check` je závěrečná brána v izolovaném prostředí.
