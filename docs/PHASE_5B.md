# Fáze 5B — UI diskuse a nepřečtené zprávy

Fáze 5B staví nad doménovým a bezpečnostním jádrem Fáze 5A. Nemění schéma, append-only model, privátní storage ani row-level pravidla. Přidává provozní UI, unread badge, frontu konverzací a bezpečné cursorové stránkování.

## Detail zakázky a diskuse

Každá autorizovaná varianta detailu zakázky obsahuje odkaz `Diskuse` s lokálním červeným badge, pokud má přihlášený uživatel nepřečtené zprávy. Samostatná diskusní stránka zobrazuje zprávy chronologicky v rámci stránky, autora, organizaci a čas. AUTOKLUBY a Polsko mají odlišnou barvu karty.

Text se vykresluje po HTML escapování a pouze se převádějí nové řádky. Aktivní HTML ani JavaScript se nespouští. Přílohy odkazují výhradně na autorizovaný download controller; storage disk ani cesta se do HTML nevykreslují.

Formulář podporuje více příloh, CSRF a idempotency key. Text se po validační chybě vrací přes old input. JavaScript po validním submit eventu zablokuje tlačítko a zobrazí stav odesílání; serverová idempotence z Fáze 5A zůstává autoritativní ochranou proti dvojímu požadavku. `READ_ONLY` formulář nevidí a server jeho POST odmítne.

## Nepřečtené zprávy

Hlavní menu obsahuje vždy viditelnou položku `Diskuse`. Červený badge se zobrazí pouze při nenulovém součtu zpráv, které:

- nenapsal aktuální uživatel;
- nemají jeho `message_reads` záznam;
- patří k zakázce dostupné přes `Order::visibleTo($user)`.

Stejný výpočet se používá pro lokální badge detailu. Otevření autorizované diskuse před vykreslením idempotentně vloží read-state ke všem dostupným nepřečteným zprávám dané zakázky. Explicitní POST `orders.discussion.read` zůstává idempotentní serverová operace.

## Fronta diskusí

`GET /discussions` (`discussions.index`) používá `OrderPolicy::viewAny` a dotaz začíná `Order::visibleTo($user)`. Zobrazuje pouze zakázky s alespoň jednou zprávou, poslední sdílený text, autora/organizaci, čas a uživatelský unread počet. Konverzace s nepřečtenými zprávami jsou první, dále rozhoduje čas poslední zprávy a ID zakázky.

Dotaz nenačítá `customer` ani `business_snapshot`. Polsko proto nedostává systémová zákaznická PII a nevidí cizí zakázky. Cizí detail diskuse, zápis i příloha jsou maskované bezpečnou 404.

## Stránkování a mobilní UI

Pevný limit 200 zpráv byl odstraněn. Diskuse používá cursor pagination po 30 položkách s úplným deterministickým pořadím `created_at DESC, id DESC`. Kolekce se obrací pouze při vykreslení; paginator zůstává v databázovém pořadí, takže cursor nevynechá ani neduplikuje zprávy. Odkazy `Načíst starší` a `Načíst novější` používají opaque cursor.

Karty, text i názvy příloh používají bezpečné zalomení. Formulář a file input mají šířku omezenou viewportem a dotykové akce minimální výšku 44 px. Tabulková fronta používá existující mobilní responsive režim pro šířky pod 700 px, včetně cílových 390 px.

## Lokalizace a testy

Český a polský slovník pokrývá menu, unread stav, frontu, formulář, přílohy, odesílání, stránkování, prázdné stavy, validační chyby a zákaz zápisu. `Phase5bDiscussionUiTest` ověřuje:

- zobrazení a vizuální rozlišení obou organizací;
- text, více příloh, CSRF, zachování vstupu a dvojité odeslání;
- prázdný/příliš dlouhý text, SVG a XSS;
- cizí URL, attachment 404, `READ_ONLY`, polský scope a absenci zákaznických PII;
- automatický idempotentní read-state, globální i lokální badge;
- pořadí a prázdný stav fronty;
- tři cursorové stránky 65 zpráv bez překryvu nebo mezery;
- české a polské texty.

Všechny testy používají SQLite `:memory:` a unikátní temp storage sandbox. Produkční migrace se nespouští, DB ani private storage se nemění, SMTP se nepřipojuje a žádný e-mail se neposílá.

Finální `composer check` prošel nad izolovanou SQLite `:memory:` databází: **326 testů, 1 442 assertions**. Součástí běhu byly test-database guard, Pint, kontrola PHP syntaxe a kompletní testovací sada.

## Známá omezení

- Fáze 5B stále neobsahuje outbox worker, retry, skutečné e-maily ani administrátorský přehled notifikací.
- Není implementován antivirus nebo content moderation příloh.
- Ručně napsaný text a obsah příloh se automaticky nepřekládají ani nekontrolují na PII; systémová zákaznická data se však do dotazů diskuse a fronty nenačítají.
- Fronta konverzací používá běžné stránkování po 50 zakázkách; cursorová garance bez překryvu se vztahuje na historii zpráv.
- Responzivní chování je pokryté strukturou/CSS a feature testy, nikoli ručním testem na fyzickém mobilním zařízení.
