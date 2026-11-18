# Intercambio de cromos · Backend

API en Laravel 13 + MySQL para intercambiar cromos repetidos entre usuarios, con aviso en tiempo real (Reverb) y caducidad asíncrona.

## Cómo ejecutarlo

```bash
docker compose up --build          # API + demo en :8000, websockets en :8081
docker compose exec app php artisan test                      # 75 tests (unit, feature y concurrencia)
docker compose exec app php artisan test --testsuite=Concurrency
docker compose exec app php artisan trading:race              # script de concurrencia por HTTP (--keep para no limpiar)
```

**Demo:** abre `http://localhost:8000/demo?user=1` y `http://localhost:8000/demo?user=2`. El usuario 1 publica «#01 Portero a cambio de #02 Defensa», el 2 la acepta y al 1 le aparece el aviso al momento.

Servicios: `mysql`, `app` (API, migra y siembra al arrancar), `queue` (envía los avisos), `scheduler` (caducidad cada minuto) y `reverb` (websockets). Reverb escucha en el 8080 dentro de la red de Docker y se publica en el **8081** del host, porque el 8080 suele estar ocupado en Windows; el navegador lo toma de `REVERB_PUBLIC_PORT`.

### API

Autenticación simulada: cabecera `X-User-Id`.

| Método | Ruta | |
|---|---|---|
| GET | `/api/offers?offered_sticker_id=&requested_sticker_id=&author_id=&per_page=&cursor=` | Ofertas abiertas (paginación por cursor, `per_page` 1–100, 20 por defecto) |
| POST | `/api/offers` `{offered_sticker_id, requested_sticker_id}` | Publicar |
| POST | `/api/offers/{id}/accept` | Aceptar |
| POST | `/api/offers/{id}/withdraw` | Retirar |
| DELETE | `/api/me` | Borrar la cuenta (retira sus ofertas y la anonimiza) |
| GET | `/api/users/{id}/collection` | Colección (`quantity`, `reserved`, `tradeable`) |
| GET | `/api/stickers` | Catálogo |

Los errores de negocio devuelven `{"error": "<code>", "message": "..."}` con un código estable: `no_spare_copy`, `offer_not_open`, `offer_expired`, `weekly_trade_limit_reached`, `cannot_accept_own_offer`, `not_offer_author`, `account_deleted`... (409 por defecto; 404, 403 o 422 donde corresponde). Los errores de forma (campos que faltan, tipos, cromos inexistentes) los validan las FormRequest y devuelven el 422 estándar de Laravel.

## Arquitectura

```
src/Trading/
  Domain/          Offer, StickerHolding (+ VO Copies), WeeklyTradeLimit, excepciones. PHP puro, sin Laravel.
  Application/     PublishOffer, AcceptOffer, WithdrawOffer, ExpireDueOffers, DeleteAccount + puertos (UserLock, Accounts, read model + OpenOffersQuery)
  Infrastructure/  Repositorios MySQL, HTTP (controladores + FormRequest), Broadcasting (Reverb), comando de caducidad, ServiceProvider
src/Shared/        Clock, TransactionManager, EventPublisher
```

Hexagonal ligera: las reglas viven en el dominio y se prueban sin BD; los casos de uso orquestan; Laravel solo aparece en los adaptadores: las FormRequest validan la forma y traducen la petición a primitivos o a un DTO (`ListOffersRequest::toQuery()` → `OpenOffersQuery`), y el controlador solo delega. El lado de lectura (listado, colección) va directo a SQL por un read model, porque leer no aplica reglas.

## Decisiones

**1. Concurrencia con bloqueo pesimista y orden fijo.** Cada caso de uso abre una transacción y bloquea con `SELECT … FOR UPDATE`, siempre en el mismo orden: **oferta → usuarios implicados (por id)**.

- Dos usuarios aceptan la misma oferta: el segundo espera al lock de la oferta y, al entrar, la ve aceptada (`offer_not_open`).
- El mismo usuario acepta varias a la vez: espera a su propio lock y, al entrar, ve sus copias ya descontadas (`no_spare_copy`).
- La misma pareja intercambia a la vez: el lock de los dos usuarios serializa el conteo semanal.
- Un usuario borra su cuenta mientras le aceptan una oferta: el borrado bloquea primero sus ofertas y después al usuario, así que gana quien coja antes el lock de la oferta (ver «Borrado de cuenta»).

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
- **Caducidad** Retirar una oferta vencida que el barrido aún no ha procesado devuelve `offer_expired`; el barrido libera el cromo en menos de un minuto.
- La oferta caduca exactamente a las 48 h (`expires_at`), pero el cromo se libera en el barrido, como mucho un minuto después. Para hacerlo exacto, los casos de uso caducarían las ofertas vencidas del usuario antes de comprobar sus copias.
- Un intercambio aceptado es definitivo.
- Cualquier usuario puede ver la colección de otro (lo necesita para decidir qué ofrecer).
- **Borrado de cuenta** (`DELETE /api/me`): no se borra la fila, se anonimiza (nombre, email y contraseña) y se marca con `deleted_at`, para que la otra parte conserve su historial de intercambios; la FK `restrict` de `offers` impide además un borrado físico accidental. En la misma transacción se retiran sus ofertas abiertas liberando las reservas (las vencidas que el barrido aún no ha procesado se marcan como caducadas). Respeta el orden de bloqueo **ofertas → usuario**: si alguien acepta a la vez, gana quien coja antes el lock de la oferta. Con el lock del usuario se relee, para recoger ofertas publicadas mientras tanto. `PublishOffer` y `AcceptOffer` comprueban, ya con el lock del usuario, que ninguna cuenta implicada esté borrada (`account_deleted`); por HTTP una cuenta borrada ya ni se autentica. Su colección se conserva tal cual. No se envían avisos al autor borrado.

## Tests

- **Unit** (dominio, sin BD): ciclo de vida de la oferta e invariantes del VO inmutable `Copies` y de `StickerHolding`.
- **Feature** (HTTP + MySQL): cada regla de negocio, caducidad con `travel()`, aviso en el canal correcto y que no se emite si la operación falla.
- **Concurrencia** (`tests/Concurrency`): cada operación en un proceso hijo (`pcntl_fork`) con su propia conexión, todos arrancando tras una barrera de tiempo. Escenarios: 20 usuarios aceptando la misma oferta, un usuario con una repetida aceptando 10, 10 intercambios simultáneos de la misma pareja, 10 publicaciones con la misma repetida, aceptar y retirar a la vez, aceptar una oferta mientras su autor borra la cuenta (o queda aceptada, o retirada con el cromo liberado) y publicar mientras se borra la cuenta (nunca queda una oferta abierta de una cuenta borrada). Además de quién gana, se comprueba que no se crean ni se destruyen cromos.

Para saber que estos tests detectan algo, se quitaron los `FOR UPDATE` y se ejecutaron: **los 5 escenarios originales fallan** (8 usuarios «ganaban» la misma oferta, la pareja hacía 10 intercambios en una semana, la misma repetida respaldaba 3 ofertas…).

`trading:race` hace lo mismo por HTTP contra la API levantada (25 peticiones simultáneas con curl multi). Crea sus propios usuarios y al terminar retira, con `WithdrawOffer`, las ofertas que quedaron sin aceptar, para no ensuciar la demo (`--keep` las conserva):

```
1) 25 usuarios aceptan la oferta #1          2) Un usuario con 1 repetida acepta 25 ofertas
| 200 accepted       | 1  |                  | 200 accepted      | 1  |
| 409 offer_not_open | 24 |                  | 409 no_spare_copy | 24 |
```

## Fuera de alcance / con más tiempo

1. **Notificaciones persistentes.** El websocket solo llega si el autor está conectado; con la app cerrada, el aviso se pierde. Guardaría una tabla `notifications` (bandeja de entrada con leídas/no leídas) y enviaría push móvil (FCM/APNs) a quien no esté conectado. El websocket pasa a ser una vía de entrega, no la fuente de verdad.
2. **Patrón outbox.** Si el proceso muere entre el commit y el encolado, el intercambio queda hecho pero el aviso se pierde. Escribiría el evento en una tabla `outbox` dentro de la misma transacción y un relay lo publicaría, con entrega at-least-once y consumidores idempotentes.
3. **Idempotencia en aceptar y publicar.** Con una mala conexión un `publish` repetido crea dos ofertas y un `accept` repetido devuelve un 409 confuso aunque el primero saliera bien. Con una cabecera `Idempotency-Key` guardada junto a su respuesta, un reintento devuelve el resultado original.
4. **Arquitectura protegida por herramientas.** PHPStan a nivel máximo y una regla de arquitectura (Deptrac o tests de arquitectura) que falle si `Domain` importa `Illuminate`, todo en CI con MySQL. Hoy la separación de capas depende de la disciplina.
5. **Contrato de API y de eventos.** OpenAPI de la API y payloads de websocket versionados (`offer.accepted` v1), para que el backend evolucione sin romperse.
6. **Seguridad: autenticación real y rate limiting.** Sustituir la cabecera `X-User-Id` por tokens (Sanctum, o el proveedor de identidad de la app móvil). Es un cambio localizado: el resto del código solo usa `$request->user()`, y la autorización de los canales privados de Reverb reutiliza el mismo guard. Límite de peticiones por usuario en publicar y aceptar para frenar bots.
7. **Documentación extra.** Un `README.md` por bounded context y otro pensado para asistentes de IA.

## Para producción con mucho tráfico

- **Runtime en AWS:** nginx + PHP-FPM u Octane detrás de un balanceador, con web, workers y scheduler como servicios separados que escalan por separado (ECS). Colas en SQS (o Redis con Horizon) y Reverb en varias réplicas con Redis pub/sub (ElastiCache).
- **Base de datos:** Aurora MySQL con **RDS Proxy**: cientos de workers PHP con conexiones propias agotan las de MySQL en un pico. Listado y colección desde réplicas con caché corta; tras escribir se lee de la primaria (`'sticky' => true`) para que el usuario vea su propio cambio.
- **Crecimiento de datos:** `offers` acumula ofertas cerradas y el camino crítico solo necesita las abiertas. Archivaría o particionaría las cerradas, sacaría el historial a una tabla `trades` con un contador por pareja y ventana para el límite semanal en vez de un `COUNT`, y haría el barrido de caducadas en lotes con `FOR UPDATE SKIP LOCKED` y varios workers.
- **Observabilidad y validación:** Sentry para errores; métricas de esperas de lock, deadlocks, reintentos, tamaño de cola y latencia del aviso (aceptación → entrega), con alertas; logs correlados por `offer_id`. Antes de un lanzamiento, pruebas de carga con escenarios con como miles de usuarios sobre las mismas ofertas, y no solo de volumen. Laravel Pulse (monitorización Websockets).

## Uso de la IA

He trabajado con Claude como pareja: yo fijaba el enfoque (hexagonal, bloqueo pesimista, Reverb) y revisaba cada paso; la IA generaba el código y los tests.

**Decisiones de diseño que revisé y ajusté**

- **VO `Copies` inmutable:** la primera versión tenía la regla de copias dentro de `StickerHolding`, que es mutable. La extraje a un value object inmutable para que la regla central (no perder la última copia ni usar una reservada) viva en un solo sitio y cada operación devuelva un estado nuevo.
- **VOs de identidad, descartados a propósito:** los ids son `int`. Valoré `UserId`, `StickerId` y `OfferId`, pero en un dominio tan pequeño añadían ceremonia en todas las capas sin ninguna regla nueva. Es lo primero que añadiría si el dominio creciera.
- **DTOs solo donde aportan:** los casos de uso de escritura reciben dos o tres ids con argumentos con nombre, así que un command por caso de uso sería ceremonia. Sí agrupé los filtros del listado en `OpenOffersQuery`, que tiene cinco parámetros opcionales, y moví la validación HTTP a FormRequests para dejar los controladores finos.
- **Varios agregados en una transacción:** `AcceptOffer` modifica la oferta y cuatro holdings a la vez, lo que rompe la regla de «un agregado por transacción». Lo mantuve porque el intercambio tiene que ser atómico; con consistencia eventual habría una ventana con un cromo entregado y el otro no.
- **Puerto `Accounts` en vez de reutilizar `UserLock`:** para el borrado de cuenta, Trading necesita saber si una cuenta está borrada. No metí esa regla en el lock (mezclaría un mecanismo con una regla de negocio) ni creé un agregado `Account` sin invariantes: un puerto pequeño con lo que Trading usa.
- **Sin Eloquent en el dominio:** las entidades son PHP puro y los repositorios mapean con query builder, para no tener dos modelos de la misma tabla y dejar los locks a la vista.