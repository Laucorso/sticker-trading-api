# Intercambio de cromos · Backend

API en Laravel 13 + MySQL para intercambiar cromos repetidos entre usuarios, con aviso en tiempo real (Reverb) y caducidad asíncrona.

**En 30 segundos**

- **Concurrencia:** bloqueo pesimista con orden fijo. El autor se bloquea en modo compartido y una oferta disputada responde `offer_busy` al momento en vez de hacer cola. Probado con procesos reales en paralelo: sin locks, los tests fallan.
- **Tiempo real:** Reverb con canal privado por usuario; el aviso se encola después del commit.
- **Caducidad:** `expires_at` manda; un barrido cada minuto libera las copias reservadas.
- **Arranque:** `docker compose up --build` y `php artisan test` (79 tests).

## Cómo ejecutarlo

```bash
docker compose up --build                                        # API + demo en :8000, websockets en :8081
docker compose exec app php artisan test                         # 79 tests (unit, feature y concurrencia)
docker compose exec app php artisan test --testsuite=Concurrency
docker compose exec app php artisan trading:race                 # concurrencia por HTTP (--keep para no limpiar)
```

**Demo:** abre `http://localhost:8000/demo?user=1` y `http://localhost:8000/demo?user=2`. El usuario 1 publica «#01 Portero a cambio de #02 Defensa», el 2 la acepta y al 1 le aparece el aviso al momento.

| Servicio | Qué hace |
|---|---|
| `mysql` | Base de datos (`trading` y `trading_test`) |
| `app` | API y demo en `:8000`; migra y siembra al arrancar |
| `queue` | Envía los avisos fuera de la petición |
| `scheduler` | Caduca las ofertas vencidas cada minuto |
| `reverb` | Websockets en `:8081` del host (el 8080 suele estar ocupado en Windows) |

## API

Autenticación simulada con la cabecera `X-User-Id`.

| Método | Ruta | |
|---|---|---|
| GET | `/api/offers?offered_sticker_id=&requested_sticker_id=&author_id=&per_page=&cursor=` | Ofertas abiertas (cursor, `per_page` 1–100, 20 por defecto) |
| POST | `/api/offers` `{offered_sticker_id, requested_sticker_id}` | Publicar |
| POST | `/api/offers/{id}/accept` | Aceptar |
| POST | `/api/offers/{id}/withdraw` | Retirar |
| DELETE | `/api/me` | Borrar la cuenta (retira sus ofertas y la anonimiza) |
| GET | `/api/users/{id}/collection` | Colección (`quantity`, `reserved`, `tradeable`) |
| GET | `/api/stickers` | Catálogo |

- **Errores de negocio:** `{"error": "<code>", "message": "..."}` con un código estable (`no_spare_copy`, `offer_not_open`, `offer_busy`, `offer_expired`, `weekly_trade_limit_reached`, `cannot_accept_own_offer`, `not_offer_author`, `account_deleted`…). 409 por defecto; 404, 403 o 422 donde corresponde.
- **Errores de validación:** los validan las FormRequest (422 estándar de Laravel).
- **Rate limiting:** 60 escrituras por minuto y usuario (429 con `Retry-After`). Evita que un cliente en bucle convierta su fila en un punto caliente de locks. Las lecturas no tienen límite.

## Arquitectura

```
src/Trading/
  Domain/          Offer, StickerHolding (+ VO Copies), WeeklyTradeLimit, excepciones. PHP puro, sin Laravel.
  Application/     PublishOffer, AcceptOffer, WithdrawOffer, ExpireDueOffers, DeleteAccount + puertos (UserLock, Accounts, read model + OpenOffersQuery)
  Infrastructure/  Repositorios MySQL, HTTP (controladores + FormRequest), Broadcasting (Reverb), comando de caducidad, ServiceProvider
src/Shared/        Clock, TransactionManager, EventPublisher
```

- **Hexagonal ligera:** las reglas viven en el dominio y se prueban sin base de datos; los casos de uso orquestan; Laravel solo aparece en los adaptadores.
- **Controladores finos:** las FormRequest validan y traducen la petición a primitivos o a un DTO (`ListOffersRequest::toQuery()` → `OpenOffersQuery`).
- **CQRS ligero:** las lecturas (listado y colección) van directas a SQL por un read model, porque leer no aplica reglas.

## Decisiones

### 1. Concurrencia: bloqueo pesimista con orden fijo

Cada caso de uso abre una transacción y bloquea antes de leer, siempre en el mismo orden: **oferta → usuarios (por id) → copias (por cromo)**.

| Carrera | Cómo se resuelve | El perdedor recibe |
|---|---|---|
| Varios aceptan la misma oferta | La oferta se bloquea con `FOR UPDATE NOWAIT`: quien llega mientras otro la procesa no hace cola | `offer_busy` (llegó durante) u `offer_not_open` (llegó después) |
| Un usuario acepta varias a la vez | Espera a su propio lock y ve sus copias ya descontadas | `no_spare_copy` |
| La misma pareja intercambia a la vez, en cualquier sentido | Los locks de los dos usuarios chocan y el conteo semanal se serializa | `weekly_trade_limit_reached` |
| Muchos aceptan ofertas distintas del mismo autor | El autor se bloquea en **compartido** y quien acepta en exclusiva: no hacen cola en el autor, solo en una misma fila de copias | Nada: todos salen |
| Se borra la cuenta mientras le aceptan una oferta | El borrado bloquea primero sus ofertas: gana quien coja antes el lock de la oferta | Si gana el borrado, la aceptación recibe `offer_busy` u `offer_not_open` |

**Detalles que lo hacen funcionar:**

- **La fila de `users` hace de mutex por usuario**, porque siempre existe.
- **Cada fila de copias que cambia se bloquea.** Si es un cromo que el usuario aún no tiene, `INSERT … ON DUPLICATE KEY UPDATE` la crea y la bloquea en el mismo paso, para no perder una copia.
- **`NOWAIT` solo al aceptar.** Retirar, caducar y borrar la cuenta esperan el lock normal, porque deben completarse.
- **Deadlocks:** el orden fijo los evita; si MySQL detecta uno, la transacción se reintenta (3 intentos).
- **Timeout de lock de 3 s** (`innodb_lock_wait_timeout`, 50 s por defecto): un worker no se queda bloqueado bajo contención. Si fallan los 3 reintentos, la petición devuelve 500.

*Descartado:*

- **Bloqueo optimista:** en una oferta disputada fallarían casi todos y habría que reintentar.
- **UPDATE condicional** (`… WHERE quantity - reserved >= 2`): muy rápido, pero reparte las reglas entre SQL y PHP y no resuelve el límite semanal.
- **Locks en Redis:** una pieza más que puede fallar y que no es transaccional con la base de datos.

### 2. Reservas como contador

`user_stickers.reserved` cuenta las copias comprometidas en ofertas abiertas. Comprobar «tiene repetidas libres» es O(1), y la base de datos lo refuerza con `CHECK (reserved < quantity OR (quantity = 0 AND reserved = 0))`. El `CHECK` es una red de seguridad, no el mecanismo: sin locks hay *lost updates* que no ve.

### 3. `READ COMMITTED`

Tras bloquear, los casos de uso leen con `SELECT` normales. Con este aislamiento, cada lectura ve lo último confirmado por quien tuvo el lock antes, sin depender de cuándo abre InnoDB el snapshot de `REPEATABLE READ`. Además, evita gap locks.

### 4. Caducidad: `expires_at` manda y un barrido limpia

Una oferta con más de 48 h no se puede aceptar ni aparece en el listado, aunque el barrido no haya pasado. `offers:expire` corre cada minuto (`withoutOverlapping` + `onOneServer`), en transacciones cortas e idempotentes, y libera la copia.

*Descartado:* un job retrasado por oferta (SQS no admite más de 15 min, miles de jobs pendientes que habría que cancelar al retirar) y eventos programados de MySQL (lógica fuera del código y de los tests).

### 5. Tiempo real con Reverb y canal privado

Al aceptar se publica `OfferAccepted` **después del commit** y se encola `OfferAcceptedNotification` (`ShouldBroadcast`) al canal `private-users.{autorId}`.

- **Después del commit:** sin avisos falsos si hay rollback.
- **Encolado:** si Reverb cae, la aceptación no falla y el envío se reintenta.
- **Canal privado:** se autoriza con la misma cabecera, y nadie puede escuchar el canal de otro.

*Descartado:* SSE (en PHP cada conexión abierta ocupa un worker). Reverb habla el protocolo de Pusher, así que pasar a Pusher, Ably o un gateway propio en NestJS es cambiar el driver.

### 6. Tests contra MySQL real, no SQLite

Los locks y los `CHECK` son parte de lo que se prueba, y SQLite ignora `FOR UPDATE`.

## Suposiciones

- **Repetido** = cualquier copia más allá de la primera. Cada oferta abierta reserva una: con 3 copias se pueden tener 2 ofertas abiertas del mismo cromo.
- **Quien acepta** también entrega un repetido: no puede dar su última copia ni una comprometida en otra oferta suya.
- Se puede pedir un cromo que ya tienes; no se puede ofrecer y pedir el mismo.
- **Semana** = ventana móvil de 7 días, no semana natural (si no, se podrían hacer 5 el domingo y 5 el lunes). Cuentan los intercambios en los dos sentidos de la pareja.
- **Caducidad:** la oferta caduca exactamente a las 48 h, pero la copia se libera en el barrido, como mucho un minuto después. Retirar una oferta vencida que el barrido aún no ha procesado devuelve `offer_expired`.
- Un intercambio aceptado es definitivo.
- Cualquier usuario puede ver la colección de otro, para saber quién tiene repetido el cromo que busca antes de publicar.
- **Borrado de cuenta:** no se borra la fila; se anonimiza (nombre, email y contraseña) y se marca con `deleted_at`, para que la otra parte conserve su historial. En la misma transacción se retiran sus ofertas abiertas y se liberan las reservas. `PublishOffer` y `AcceptOffer` comprueban, con el lock del usuario, que ninguna cuenta implicada esté borrada. Su colección se conserva y no se envían avisos al autor borrado.

## Tests

- **Unit** (dominio, sin BD): ciclo de vida de la oferta e invariantes de `Copies` y `StickerHolding`.
- **Feature** (HTTP + MySQL): cada regla de negocio, caducidad con `travel()`, aviso en el canal correcto y que no se emite si la operación falla.
- **Concurrencia** (`tests/Concurrency`): cada operación en un proceso hijo (`pcntl_fork`) con su propia conexión, todos arrancando a la vez tras una barrera. Además de quién gana, se comprueba que no se crean ni se destruyen cromos.

| Escenario | Resultado esperado |
|---|---|
| 20 usuarios aceptan la misma oferta | Gana uno; el resto, `offer_busy` u `offer_not_open` |
| Aceptar una oferta que otra transacción tiene bloqueada | `offer_busy` al momento, sin esperar |
| Un usuario con una sola repetida acepta 10 ofertas | Gana una; el resto, `no_spare_copy` |
| 10 intercambios simultáneos de la misma pareja (también en los dos sentidos) | 5 salen; el resto, `weekly_trade_limit_reached` |
| 30 usuarios aceptan 30 ofertas distintas del mismo autor | Salen todas y el autor no pierde ni gana copias |
| 10 publicaciones con la misma repetida | Solo una reserva la copia |
| Aceptar y retirar a la vez | Gana solo una de las dos |
| Aceptar mientras el autor borra la cuenta | O queda aceptada, o retirada con la copia liberada |
| Publicar mientras se borra la cuenta | Nunca queda una oferta abierta de una cuenta borrada |

**Para saber que detectan algo,** se quitaron los `FOR UPDATE` y se ejecutaron: los 5 escenarios originales fallan (8 usuarios «ganaban» la misma oferta, la pareja hacía 10 intercambios en una semana, la misma repetida respaldaba 3 ofertas…).

**`trading:race`** hace lo mismo por HTTP contra la API levantada (25 peticiones simultáneas). Crea sus propios usuarios y al terminar retira las ofertas sin aceptar, para no ensuciar la demo (`--keep` las conserva). Resultado de antes de `NOWAIT`; ahora, en el escenario 1, los 24 perdedores se reparten entre `offer_busy` y `offer_not_open`:

```
1) 25 usuarios aceptan la oferta #1          2) Un usuario con 1 repetida acepta 25 ofertas
| 200 accepted       | 1  |                  | 200 accepted      | 1  |
| 409 offer_not_open | 24 |                  | 409 no_spare_copy | 24 |
```

## Fuera de alcance / con más tiempo

1. **Avisos que no se pierden.** Hoy se pierde un aviso si el autor no está conectado, si falla el encolado (se registra en el log y la aceptación responde 200 igualmente) o si el proceso muere justo después del commit. Los tres casos se resuelven con una tabla `notifications` escrita **en la misma transacción** que el intercambio: hace de outbox y de bandeja de entrada. Un worker envía las pendientes por websocket o push móvil (FCM/APNs) y reintenta si falla, sin afectar nunca al intercambio. El cliente ignora los avisos repetidos por id.
2. **Idempotencia en aceptar y publicar.** Con mala conexión, un `publish` repetido crea dos ofertas y un `accept` repetido devuelve un 409 confuso aunque el primero saliera bien. Una cabecera `Idempotency-Key` guardada con su respuesta haría que el reintento devuelva el resultado original.
3. **Arquitectura protegida por herramientas.** PHPStan a nivel máximo y una regla de arquitectura (Deptrac o tests) que falle si `Domain` importa `Illuminate`, todo en CI con MySQL.
4. **Contrato de API y de eventos.** OpenAPI y payloads de websocket versionados (`offer.accepted` v1).
5. **Autenticación real.** Sustituir `X-User-Id` por tokens (Sanctum o el proveedor de identidad de la app). Es un cambio localizado: el resto del código solo usa `$request->user()`, y los canales de Reverb reutilizan el mismo guard.

## Para producción con mucho tráfico

- **Runtime en AWS:** nginx + PHP-FPM u Octane detrás de un balanceador; web, workers y scheduler como servicios que escalan por separado (ECS). Colas en SQS (o Redis con Horizon) y Reverb en varias réplicas con Redis pub/sub (ElastiCache).
- **Contención:** el lock es por fila, así que el volumen general no le afecta; el riesgo son las filas calientes. La oferta disputada ya no hace cola (`NOWAIT`) y las aceptaciones a un autor popular ya no esperan en su fila (lock compartido). Solo esperan las que tocan el mismo cromo del autor: esa cola es inherente, porque compiten por las mismas copias, y es corta, porque las copias se bloquean al final de la transacción.
- **Base de datos:** Aurora MySQL con **RDS Proxy**, porque cientos de workers PHP con conexiones propias agotan las de MySQL en un pico. Listado y colección desde réplicas con caché corta; tras escribir, lectura de la primaria (`'sticky' => true`).
- **Crecimiento de datos:** archivar o particionar las ofertas cerradas; historial en una tabla `trades` con índice por pareja y fecha (conteo semanal barato, ventana móvil intacta); barrido en lotes con `FOR UPDATE SKIP LOCKED` y varios workers.
- **Observabilidad:** Sentry para errores, Horizon para las colas y Laravel Pulse con los recolectores de Reverb. Métricas de esperas de lock, deadlocks, reintentos, `offer_busy`, 429 y latencia del aviso, con alertas. Antes de un lanzamiento, pruebas de carga (k6) con contención real, no solo de volumen.

## Uso de la IA

He trabajado con Claude como pareja de programación, por pasos pequeños y con un commit por paso:

1. **Enfoque primero:** a partir del enunciado fijé la arquitectura (hexagonal ligera), la estrategia de concurrencia (bloqueo pesimista con orden fijo) y el tiempo real (Reverb con cola) antes de generar código.
2. **Construcción por capas:** dominio, casos de uso, infraestructura, API, tiempo real y Docker. La IA generaba el código y los tests de cada capa, y yo revisaba el diff antes de seguir.
3. **Verificar, no confiar:** tests contra MySQL real, tests de concurrencia validados quitando los locks a propósito, y la primera ejecución real en Docker hecha por mí.
4. **Ajustes propios:** después de la primera versión hice los cambios que se describen abajo.

**Decisiones de diseño que revisé y ajusté**

- **Lock compartido del autor al aceptar:** antes se bloqueaba en exclusiva a los dos usuarios, así que 30 aceptaciones de ofertas distintas de un mismo autor iban de una en una sin competir. Ahora van en paralelo y solo esperan las que tocan el mismo cromo (ver «Decisiones», punto 1). Lo comprueba un test con 30 aceptaciones simultáneas.
- **Aceptar sin hacer cola (`NOWAIT`):** antes, miles de aceptaciones sobre la misma oferta acababan en timeout y en 500 tras los reintentos. Ahora quien llega mientras otro la procesa recibe `offer_busy` al momento.
- **VO `Copies` inmutable:** saqué la regla de copias de `StickerHolding`, que es mutable, para que viva en un solo sitio y cada operación devuelva un estado nuevo.
- **VOs de identidad, descartados a propósito:** los ids son `int`. `UserId`, `StickerId` y `OfferId` añadían ceremonia sin ninguna regla nueva; es lo primero que añadiría si el dominio creciera.
- **DTOs solo donde aportan:** los casos de uso reciben entre uno y tres ids con argumentos con nombre. Solo agrupé en `OpenOffersQuery` los cinco filtros opcionales del listado.
- **Varios agregados en una transacción:** `AcceptOffer` modifica la oferta y cuatro holdings a la vez. Rompe la regla de «un agregado por transacción», pero el intercambio tiene que ser atómico.
- **Puerto `Accounts`:** saber si una cuenta está borrada es una regla, no un mecanismo, así que no lo metí en `UserLock` ni creé un agregado `Account` sin invariantes.
- **Sin Eloquent en el dominio:** entidades en PHP puro y repositorios con query builder, para no tener dos modelos de la misma tabla y dejar los locks a la vista.
