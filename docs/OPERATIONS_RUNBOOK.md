# Operations runbook

## Bridge alert

Ověřte čerstvost heartbeat a poslední confirmation. Známý čerstvý `PARTIAL` s 2482 accepted a dvěma `DUPLICATE_PRODUCT_REFERENCE` je použitelný. Task Scheduler Last Run Result, čas systému a lokální log konektoru ověřte ručně; portál je nevydává za automaticky ověřené. Nezapínejte bridge command whitelist.

## Backup/recovery alert

Spusťte read-only `backup:verify` nad evidovaným COMPLETE balíčkem. Při stale záloze vytvořte nový balíček schváleným postupem. Při recovery evidence warningu proveďte a evidujte skutečný izolovaný restore; sidecar ani manifest ručně neupravujte. Cesty, checksumy a credentials nevkládejte do alert poznámky.

U `scheduled_backup` rozlište `FAILED`, `SKIPPED_LOCKED` a zastaralý `SUCCESS`. Při locku ověřte právě běžící ruční/plánovaný backup a TTL; lock neuvolňujte násilně, dokud není vyloučen živý creator. Při `POINTER_UPDATE_FAILED` zachovejte předchozí pointer a samostatně ověřte dokončený balíček. Nikdy zpětně nevytvářejte automatickou off-server atestaci a nemažte staré balíčky bez schválené retenční operace. Provozní cíle jsou RPO 24 hodin a RTO 8 hodin.

## Disk/storage alert

Nejdřív ověřte agregované volné místo a počty orphan/missing. Nezapisujte názvy zákaznických souborů do ticketu, logu ani ACK. Mazání souborů nebo retence vyžaduje samostatné schválení a ověřenou zálohu.

## Outbox/integration alert

Rozlište pending, retry, stale lock a dead-letter agregované počty. Nezobrazujte payload. Před retry ověřte idempotenci konkrétního workeru. Monitoring sám retry ani obchodní změnu neprovádí.

## Monitor/scheduler alert

Lock contention znamená překrytý běh; stale RUNNING znamená nedokončený běh po vypršení locku. Ověřte serverový `schedule:run`, cache lock backend a poslední `monitor_runs`. `onOneServer` nepřidávejte bez skutečně sdíleného lock backendu.

## Health endpoint

`GET /health` je minimální readiness signál aplikace, DB a runtime storage. Vrací jen `OK` (200) nebo `UNAVAILABLE` (503), je rate-limitovaný a není závislý na Pohodě, SMTP, záloze ani TLS checku. Detail patří pouze do AK_ADMIN UI/CLI.

## ACK

ACK znamená převzetí, nikoli opravu. Poznámka musí být stručná, provozní a bez PII, secrets, cest a obsahu dokumentů. Po opravě musí další monitorovací běh vytvořit RESOLVED automaticky.
