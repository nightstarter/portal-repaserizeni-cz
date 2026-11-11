# Integrace

Ve Fázi 0 nejsou aktivní žádné produkční integrace.

## Starý e-shop

- `LEGACY_SHOP_IMPORT_ENABLED=false` je povinný výchozí stav.
- Připojení, read-only mapování, diagnostika a import patří do Fáze 1.
- Import se nesmí zapnout bez ověřeného mapování a výslovně potvrzeného `LEGACY_SHOP_IMPORT_FROM` nebo `LEGACY_SHOP_MIN_ORDER_ID`.

## Pohoda

- `POHODA_BRIDGE_ENABLED=false` je povinný výchozí stav.
- Portál nesmí navazovat příchozí spojení do firemní sítě.
- Bridge a podepisované API patří do Fáze 7.
