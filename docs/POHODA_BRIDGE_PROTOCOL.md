# Pohoda bridge protocol v1

Tento dokument popisuje společný kontrakt portálu a samostatného HPSERVER konektoru. Portál se nikdy nepřipojuje k SQL databázi Pohody; konektor iniciuje pouze odchozí HTTPS požadavky na portál a neotevírá příchozí port.

## Transport a autentizace

Všechny endpointy používají HTTPS a prefix `/api/internal/v1`. Session cookie ani CSRF se nepoužívají. Každý HTTP pokus musí mít nový kryptograficky náhodný nonce a tyto hlavičky:

- `X-Bridge-Key-Id`: identifikátor aktivního nebo předchozího klíče;
- `X-Bridge-Timestamp`: UTC Unix time v sekundách, přesně 10 číslic;
- `X-Bridge-Nonce`: 16–128 znaků `[A-Za-z0-9_-]`;
- `X-Bridge-Signature`: 64 znaků lowercase hex.

Secret je nejméně 32 náhodných bajtů uložených v `.env` jako Base64. Pro výpočet HMAC se Base64 nejprve striktně dekóduje. Algoritmus je HMAC-SHA256 a porovnání probíhá přes `hash_equals`. Výchozí tolerance času je ±300 sekund a konfigurace ji nemůže zvýšit nad 300 sekund.

Při `POHODA_BRIDGE_ENABLED=false` API vrací `503 BRIDGE_UNAVAILABLE` ještě před HMAC kontrolou a nic nezapisuje. Volitelný IP allowlist je pouze další filtr, nikoli náhrada podpisu.

## Kanonický řetězec

Kanonický řetězec je následujících šest UTF-8/ASCII částí v přesném pořadí. Části odděluje jediný bajt LF (`0x0A`); za poslední částí LF není:

```text
UPPERCASE_HTTP_METHOD\n
PATH\n
CANONICAL_QUERY\n
TIMESTAMP\n
NONCE\n
LOWERCASE_SHA256_HEX_OF_RAW_BODY
```

`PATH` je pouze absolutní path začínající `/`, bez scheme, hostu a query. Hash těla se počítá nad přesnými přijatými raw HTTP bajty před JSON dekódováním; změna whitespace v JSON tedy mění podpis. Prázdné tělo má standardní SHA-256 `e3b0c442…b855`.

Canonical query vznikne z dekódovaných query parametrů takto:

1. vnořené hodnoty se rozloží PHP bracket notací (`filter[state]`);
2. dvojice se seřadí vzestupně nejprve podle názvu a potom podle hodnoty;
3. název i hodnota se kódují RFC 3986 (`rawurlencode`, mezera je `%20`, hex je uppercase);
4. dvojice mají tvar `name=value` a spojují se `&`;
5. bez query je tato část prázdná.

Konektor nesmí posílat duplicitní nebracketované query klíče, protože je HTTP/PHP parser nedokáže bezeztrátově reprezentovat.

### Neprodukční testovací vektor

Secret je 32 ASCII znaků `AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA` (pouze testovací), timestamp `1700000000`, nonce `0123456789abcdef0123456789abcdef`, metoda `GET`, path `/api/internal/v1/commands`, query vstup `z=last&a=hello%20world&nested[b]=2&nested[a]=1` a raw body je prázdné.

Canonical query:

```text
a=hello%20world&nested%5Ba%5D=1&nested%5Bb%5D=2&z=last
```

Výsledný HMAC-SHA256 lowercase hex:

```text
98ca040e1cb2df045afc2d0a64d21b1a7463fb05ae28487210427ff06c8ea7f4
```

## Replay a idempotence

Nonce je jednorázový pro konkrétní key ID a ukládá se až po validním podpisu. Jeho opakování vrací obecné `401 BRIDGE_AUTH_FAILED`. Doménové klíče jsou nezávislé:

- snapshot run: `snapshot_run_id` UUID;
- snapshot chunk: `batch_id` UUID;
- heartbeat: `event_id` UUID;
- výsledek příkazu: `result_id` UUID.

Stejný doménový klíč a stejný payload s novým nonce vrací idempotentní úspěch. Stejný klíč s jiným obsahem vrací `409 IDEMPOTENCY_CONFLICT` (u výsledku `COMMAND_LEASE_CONFLICT`) a nic nepřepíše. Unikátní DB omezení chrání i souběžné požadavky.

## Endpointy a JSON

### `POST /stock-snapshots`

Maximální velikost těla je standardně 2 MiB a chunk má nejvýše 500 položek. Snapshot s více položkami se rozdělí na jeden run s několika chunky:

```json
{
  "snapshot_run_id": "00000000-0000-4000-8000-000000000010",
  "batch_id": "00000000-0000-4000-8000-000000000001",
  "chunk_index": 0,
  "chunk_count": 3,
  "final_chunk": false,
  "source_generated_at": "2026-11-19T09:30:00Z",
  "warehouse_code": "DECIN",
  "items": [
    {
      "product_reference": "TRW-123",
      "available_quantity": 4,
      "reserved_quantity": 1,
      "source_row_version": "row-42"
    }
  ]
}
```

`chunk_index` je nula-based a musí být menší než `chunk_count`. `final_chunk=true` je povoleno právě pro index `chunk_count - 1`. Všechny chunky runu mají stejné `snapshot_run_id`, `chunk_count`, `source_generated_at` a `warehouse_code`; každý má vlastní stabilní `batch_id`. Při retry musí konektor poslat přesně stejné raw payload bytes a stejné ID, ale nový nonce/timestamp.

Odpověď `202` obsahuje `snapshot_run_id`, `batch_id`, stav chunku, `run_status`, index/počet, počty, `idempotent_replay` a pro každou pozici pouze `ACCEPTED` nebo `REJECTED` s bezpečným kódem (`INVALID_ITEM`, `DUPLICATE_PRODUCT_REFERENCE`, `UNKNOWN_PRODUCT_REFERENCE`). Chybná položka nevrací zpět správné položky.

Run zůstává `RECEIVING`, dokud nejsou přijaté všechny pozice a finální marker. Teprve potom se atomicky změní na `COMPLETE` (bez položkových chyb) nebo `PARTIAL` (alespoň jedna položková chyba). `RECEIVING` ani timeoutovaný `FAILED` run se nikdy nepoužije pro aktuální dostupnost. Timeout se vyhodnotí při následujícím validním snapshotu nebo heartbeat. Starší dokončený run nepřepíše novější. Pokud je produkt v posledním `COMPLETE` runu nepřítomný, je nedostupný; u `PARTIAL` runu je nepřítomnost nekonkluzivní. Data starší než `POHODA_BRIDGE_STALE_MINUTES` vrací `STALE`, takže rozhodnutí zůstane `PENDING_STOCK_CHECK`.

Pro zpětnou kompatibilitu lze vynechat všechna čtyři chunk pole (`snapshot_run_id`, `chunk_index`, `chunk_count`, `final_chunk`); server takový 7A payload interpretuje jako jednodílný run. Částečné uvedení chunk polí je chyba 422.

### `POST /heartbeat`

```json
{
  "event_id": "00000000-0000-4000-8000-000000000002",
  "connector_id": "hpserver-1",
  "connector_version": "1.0.0",
  "occurred_at": "2026-11-19T09:31:00Z",
  "last_pohoda_read_at": "2026-11-19T09:30:55Z",
  "last_snapshot_at": "2026-11-19T09:30:00Z",
  "last_command_poll_at": "2026-11-19T09:30:30Z",
  "status": "OK",
  "error_codes": []
}
```

Povoleny jsou jen stavy `OK`, `DEGRADED`, `ERROR`, UTC časy a nejvýše deset bezpečných kódů `[A-Z0-9_.-]`. Connection string, hostname, hesla, cesty, stack trace a další pole jsou odmítnuty.

### `GET /commands?connector_id=hpserver-1&limit=50`

Vrací pouze typy z interního whitelistu. Ve fázi 7A je produkční whitelist prázdný; `NOOP` je povolen jen explicitní testovací konfigurací. Obecné SQL není příkazový typ ani povolený payload.

```json
{
  "commands": [{
    "command_id": "00000000-0000-4000-8000-000000000003",
    "type": "NOOP",
    "payload": {"probe": "health"},
    "lease_token": "00000000-0000-4000-8000-000000000004",
    "lease_expires_at": "2026-11-19T09:36:00Z"
  }]
}
```

Claim probíhá pod řádkovým zámkem. Po expiraci lease může příkaz převzít další polling; starý token už výsledek nepotvrdí. Konektor má při síťové chybě znovu pollovat s backoffem, nikdy si nevyrábět SQL příkaz.

### `POST /commands/{command_id}/result`

```json
{
  "result_id": "00000000-0000-4000-8000-000000000005",
  "lease_token": "00000000-0000-4000-8000-000000000004",
  "status": "SUCCESS",
  "external_code": null,
  "completed_at": "2026-11-19T09:32:00Z",
  "metadata": {"processed_count": 1, "duration_ms": 25, "message_code": "OK"}
}
```

Metadata má pevný allowlist. SQL text, stack trace a libovolná chybová zpráva se nepřijímají. Chybový výsledek jednoho příkazu neblokuje jiné příkazy.

## Chybové kódy

- `BRIDGE_UNAVAILABLE` (503): bridge je vypnutý;
- `BRIDGE_AUTH_FAILED` (401): obecná odpověď pro key ID, podpis, čas, nonce nebo IP;
- `REQUEST_TOO_LARGE` (413): překročen limit raw těla;
- `IDEMPOTENCY_CONFLICT` (409): doménový klíč byl použit s jiným payloadem;
- `COMMAND_LEASE_CONFLICT` (409): neplatný/expirující lease nebo konfliktní výsledek;
- `429 Too Many Requests`: rate limit;
- validační odpověď `422`: neplatné schéma bez interních detailů.

## Rotace a provoz

Portál přijímá současný i předchozí key ID/secret. Bezpečný postup je: nastavit nový pár jako current a starý jako previous, obnovit cache konfigurace, přepnout konektor, ověřit heartbeat, poté předchozí pár odstranit. Key ID nesmí být znovu použit s jiným secret během životnosti nonce. Hodiny konektoru musí být synchronizované NTP; při odchylce přes toleranci se požadavek odmítne.

Konektor retryuje pouze s novým nonce a novým timestampem, ale se stejným doménovým ID a raw payloadem. HTTP 408, 429 a 5xx používají exponenciální backoff s jitterem; `Retry-After` má přednost. Ostatní 4xx se automaticky neopakují a spool položka se zablokuje pro ruční zásah. Secret, podpis, raw body ani interní výjimky se nelogují.
