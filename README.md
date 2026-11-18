# Intercambio de cromos · Backend

API en Laravel 13 + MySQL para intercambiar cromos repetidos entre usuarios, con aviso en tiempo real (Reverb) y caducidad asíncrona.

## Cómo ejecutarlo

```bash
docker compose up --build          # API + demo en :8000, websockets en :8080
docker compose exec app php artisan test                      # 54 tests (unit, feature y concurrencia)
docker compose exec app php artisan test --testsuite=Concurrency
docker compose exec app php artisan trading:race              # script de concurrencia por HTTP
```

**Demo:** abre `http://localhost:8000/demo?user=1` y `http://localhost:8000/demo?user=2`. El usuario 1 publica «#01 Portero a cambio de #02 Defensa», el 2 la acepta y al 1 le aparece el aviso al momento.

Servicios: `mysql`, `app` (API, migra y siembra al arrancar), `queue` (envía los avisos), `scheduler` (caducidad cada minuto) y `reverb` (websockets).

### API

Autenticación simulada: cabecera `X-User-Id`.

| Método | Ruta | |
|---|---|---|
| GET | `/api/offers?offered_sticker_id=&requested_sticker_id=&author_id=&cursor=` | Ofertas abiertas (paginación por cursor) |
| POST | `/api/offers` `{offered_sticker_id, requested_sticker_id}` | Publicar |
| POST | `/api/offers/{id}/accept` | Aceptar |
| POST | `/api/offers/{id}/withdraw` | Retirar |
| GET | `/api/users/{id}/collection` | Colección (`quantity`, `reserved`, `tradeable`) |
| GET | `/api/stickers` | Catálogo |

Los errores de negocio devuelven `{"error": "<code>", "message": "..."}` con un código estable: `no_spare_copy`, `offer_not_open`, `offer_expired`, `weekly_trade_limit_reached`, `cannot_accept_own_offer`, `not_offer_author`... (409 por defecto; 404, 403 o 422 donde corresponde).

## Arquitectura

```
src/Trading/
  Domain/          Offer, StickerHolding, WeeklyTradeLimit, excepciones. PHP puro, sin Laravel.
  Application/     PublishOffer, AcceptOffer, WithdrawOffer, ExpireDueOffers + puertos (UserLock, read model)
  Infrastructure/  Repositorios MySQL, HTTP, Broadcasting (Reverb), comando de caducidad, ServiceProvider
src/Shared/        Clock, TransactionManager, EventPublisher
```

Hexagonal ligera: las reglas viven en el dominio y se prueban sin BD; los casos de uso orquestan; Laravel solo aparece en los adaptadores. El lado de lectura (listado, colección) va directo a SQL por un read model, porque leer no aplica reglas.

## Decisiones

**1. Concurrencia con bloqueo pesimista y orden fijo.** Cada caso de uso abre una transacción y bloquea con `SELECT … FOR UPDATE`, siempre en el mismo orden: **oferta → usuarios implicados (por id)**.

- Dos usuarios aceptan la misma oferta: el segundo espera al lock de la oferta y, al entrar, la ve aceptada (`offer_not_open`).
- El mismo usuario acepta varias a la vez: espera a su propio lock y, al entrar, ve sus copias ya descontadas (`no_spare_copy`).
- La misma pareja intercambia a la vez: el lock de los dos usuarios serializa el conteo semanal.

La fila de `users` hace de mutex por usuario porque siempre existe. Bloquear filas de `user_stickers` no sirve para el cromo que vas a recibir (aún no existe): generaría gap locks y deadlocks al insertar. El orden fijo evita deadlocks y, si MySQL detecta uno igualmente, la transacción se reintenta (3 intentos).

*Descartado:* **optimistic locking** con versión (en una oferta disputada fallarían casi todos y habría que reintentar), **UPDATE condicional atómico** (`… WHERE quantity - reserved >= 2`, muy rápido, pero reparte las reglas entre SQL y PHP y no resuelve el límite semanal) y **locks en Redis** (una pieza más que puede fallar y que no es transaccional con la BD).

**2. Reservas como contador.** `user_stickers.reserved` cuenta las copias comprometidas en ofertas abiertas. Comprobar «tiene repetidas libres» es O(1) y la BD lo refuerza con `CHECK (reserved < quantity OR (quantity = 0 AND reserved = 0))`. El CHECK es una red de seguridad, no el mecanismo: sin locks se producen lost updates que el CHECK no ve (ver «Tests»).

**3. `READ COMMITTED`.** Tras bloquear, los casos de uso leen con SELECT normales. Con este aislamiento cada lectura ve lo último confirmado por quien tuvo el lock antes, sin depender de cuándo abre InnoDB el snapshot de `REPEATABLE READ`. Además evita gap locks.

**4. Caducidad: `expires_at` manda y un barrido limpia.** Una oferta con más de 48 h no se puede aceptar ni aparece en el listado aunque el job no haya pasado. `offers:expire` corre cada minuto (`withoutOverlapping` + `onOneServer`), caduca en transacciones cortas e idempotentes y libera el cromo. *Descartado:* un job retrasado por oferta (SQS no admite más de 15 min de retraso, miles de jobs pendientes y hay que cancelarlos al retirar) y eventos de MySQL (lógica fuera del código y de los tests).

**5. Tiempo real con Reverb y canal privado.** Al aceptar se publica `OfferAccepted` **después del commit** y se encola `OfferAcceptedNotification` (`ShouldBroadcast`) al canal `private-users.{autorId}`. El worker lo envía a Reverb, así que un Reverb caído no rompe la aceptación y el envío se reintenta. El canal se autoriza con la misma cabecera, y nadie puede escuchar el canal de otro. *Descartado:* SSE (cada conexión abierta ocupa un worker PHP). Reverb usa el protocolo de Pusher, así que pasar a un gateway propio en NestJS o a Pusher/Ably es cambiar el driver.

**6. Tests contra MySQL real, no SQLite.** Los locks y los CHECK son parte de lo que se prueba; SQLite ignora `FOR UPDATE`.

## Suposiciones

- **Repetido** = cualquier copia más allá de la primera. Con 3 copias se pueden tener 2 ofertas abiertas del mismo cromo.
- Quien acepta también entrega un repetido: no puede dar su última copia ni una comprometida en otra oferta suya.
- Se puede pedir un cromo que ya tienes; no se puede ofrecer y pedir el mismo.
- **Semana** = ventana móvil de 7 días, no semana natural (si no, se podrían hacer 5 el domingo y 5 el lunes). Cuentan los intercambios en los dos sentidos de la pareja.
- Retirar una oferta vencida que el barrido aún no ha procesado devuelve `offer_expired`; el barrido libera el cromo en menos de un minuto.
- Un intercambio aceptado es definitivo.
- Cualquier usuario puede ver la colección de otro (lo necesita para decidir qué ofrecer).
- **Borrado de cuenta** (no implementado): en una transacción con el lock del usuario se retirarían sus ofertas abiertas (liberando reservas) y se anonimizaría la cuenta en vez de borrarla, para no perder el historial de la otra parte. La FK `restrict` de `offers` impide hoy un borrado físico accidental.

## Tests

- **Unit** (dominio, sin BD): ciclo de vida de la oferta e invariantes de `StickerHolding`.
- **Feature** (HTTP + MySQL): cada regla de negocio, caducidad con `travel()`, aviso en el canal correcto y que no se emite si la operación falla.
- **Concurrencia** (`tests/Concurrency`): cada operación en un proceso hijo (`pcntl_fork`) con su propia conexión, todos arrancando tras una barrera de tiempo. Escenarios: 20 usuarios aceptando la misma oferta, un usuario con una repetida aceptando 10, 10 intercambios simultáneos de la misma pareja, 10 publicaciones con la misma repetida, y aceptar y retirar a la vez. Además de quién gana, se comprueba que no se crean ni se destruyen cromos.

Para saber que estos tests detectan algo, se quitaron los `FOR UPDATE` y se ejecutaron: **los 5 fallan** (8 usuarios «ganaban» la misma oferta, la pareja hacía 10 intercambios en una semana, la misma repetida respaldaba 3 ofertas…).

`trading:race` hace lo mismo por HTTP contra la API levantada (25 peticiones simultáneas con curl multi):

```
1) 25 usuarios aceptan la oferta #1          2) Un usuario con 1 repetida acepta 25 ofertas
| 200 accepted       | 1  |                  | 200 accepted      | 1  |
| 409 offer_not_open | 24 |                  | 409 no_spare_copy | 24 |
```

## Fuera de alcance / con más tiempo

- Idempotency keys en aceptar y publicar (un reintento del móvil no debería dar un 409 confuso).
- Rate limiting por usuario, borrado de cuenta, OpenAPI, PHPStan y CI.
- Patrón outbox (ver abajo).

## Para producción con mucho tráfico

- **Avisos fiables:** hoy, si el proceso muere entre el commit y el encolado, el aviso se pierde (el intercambio no). Una tabla *outbox* escrita en la misma transacción y un relay lo garantizan.
- **Runtime:** nginx + PHP-FPM u Octane en lugar de `artisan serve`; colas en Redis/SQS con Horizon; Reverb escalado horizontalmente con Redis pub/sub (o el gateway de NestJS).
- **Contención:** el lock por usuario serializa solo las operaciones de ese usuario, suficiente aquí. Si un usuario muy activo se convirtiera en un punto caliente, pasaría a UPDATE condicionales atómicos para las reservas.
- **Lecturas:** listado y colección desde réplicas, con caché corta para el listado.
- **Barrido:** varios workers con `FOR UPDATE SKIP LOCKED` si el volumen de caducadas lo pide.
- **Observabilidad:** métricas de esperas de lock, deadlocks y reintentos, latencia del aviso (aceptación → entrega) y tamaño de la cola.

## Uso de la IA

He trabajado con Claude como pareja: yo fijaba el enfoque (hexagonal, bloqueo pesimista, Reverb) y revisaba cada paso; la IA generaba el código y los tests. Lo que hubo que corregir por el camino:

- **Off-by-one en la ventana semanal:** el conteo usaba `>=` y a los 7 días exactos el intercambio antiguo seguía contando. Lo detectó el test de ventana móvil.
- **Tests de concurrencia que podían pasar «por suerte»:** se validaron rompiendo el código a propósito (sin locks) y comprobando que fallan.
- **Autenticación en tests:** el guard cacheaba el primer usuario entre peticiones del mismo test, así que la cabecera `X-User-Id` dejaba de mandar. Se olvidan los guards en cada petición del test.
- **Nombres:** un evento y una excepción se llamaban igual (`OfferExpired`); la excepción pasó a `OfferAlreadyExpired`.
