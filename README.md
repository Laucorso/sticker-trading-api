# Intercambio de cromos · Backend

API en Laravel 13 + MySQL para intercambiar cromos repetidos entre usuarios, con aviso en tiempo real (Reverb) y caducidad asíncrona.

## Cómo ejecutarlo

```bash
docker compose up --build          # API + demo en :8000, websockets en :8081
docker compose exec app php artisan test                      # 79 tests (unit, feature y concurrencia)
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

- Los errores de negocio devuelven `{"error": "<code>", "message": "..."}` con un código estable: `no_spare_copy`, `offer_not_open`, `offer_busy`, `offer_expired`, `weekly_trade_limit_reached`, `cannot_accept_own_offer`, `not_offer_author`, `account_deleted`... (409 por defecto; 404, 403 o 422 donde corresponde). Los errores de forma (campos que faltan, tipos, cromos inexistentes) los validan las FormRequest y devuelven el 422 estándar de Laravel.
- Las escrituras (publicar, aceptar, retirar y `DELETE /api/me`) tienen un límite de 60 peticiones por minuto por usuario, para que un cliente en bucle no convierta su fila de `users` en un punto caliente de locks. Al superarlo se devuelve 429 con `Retry-After` y el formato de Laravel (`{"message": "Too Many Attempts."}`), sin el campo `error`. Por eso `trading:race` con `--requests` mayor que 60 recibiría 429 en el escenario 2, donde un mismo usuario acepta todas las ofertas.

## Arquitectura

```
src/Trading/
  Domain/          Offer, StickerHolding (+ VO Copies), WeeklyTradeLimit, excepciones. PHP puro, sin Laravel.
  Application/     PublishOffer, AcceptOffer, WithdrawOffer, ExpireDueOffers, DeleteAccount + puertos (UserLock, Accounts, read model + OpenOffersQuery)
  Infrastructure/  Repositorios MySQL, HTTP (controladores + FormRequest), Broadcasting (Reverb), comando de caducidad, ServiceProvider
src/Shared/        Clock, TransactionManager, EventPublisher
```

- Hexagonal ligera: las reglas viven en el dominio y se prueban sin BD; los casos de uso orquestan; Laravel solo aparece en los adaptadores: las FormRequest validan la forma y traducen la petición a primitivos o a un DTO (`ListOffersRequest::toQuery()` → `OpenOffersQuery`), y el controlador solo delega. El lado de lectura (listado, colección) va directo a SQL por un read model, porque leer no aplica reglas.

## Decisiones

**1. Concurrencia con bloqueo pesimista y orden fijo.** Cada caso de uso abre una transacción y bloquea con `SELECT … FOR UPDATE`, siempre en el mismo orden: **oferta → usuarios implicados (por id)**.

- Varios usuarios aceptan la misma oferta: al aceptar, la oferta se bloquea con `FOR UPDATE NOWAIT`. Quien llega mientras otro la procesa recibe al momento un 409 `offer_busy` en vez de hacer cola (en un pico sobre una sola oferta, la cola acababa en timeout y en 500 tras los reintentos); quien llega después del commit la ve aceptada (`offer_not_open`). No es una regla de negocio sino de concurrencia, pero usa el mismo contrato de error para que el cliente pueda reintentar. Retirar, caducar y borrar la cuenta sí esperan al lock: no deben fallar por una aceptación en curso.
- El mismo usuario acepta varias a la vez: espera a su propio lock y, al entrar, ve sus copias ya descontadas (`no_spare_copy`).
- La misma pareja intercambia a la vez, en cualquier sentido: el lock de los dos usuarios serializa el conteo semanal.
- Muchos usuarios aceptan a la vez ofertas distintas del mismo autor: al aceptar, el autor se bloquea en **modo compartido** (`FOR SHARE`) y quien acepta en exclusiva. Los locks compartidos no se esperan entre sí, así que no hacen cola en la fila del autor; solo coinciden en sus filas de `user_stickers` cuando tocan el mismo cromo, y esas se bloquean por orden de cromo. El límite semanal sigue serializado: si A acepta una oferta de B mientras B acepta una de A, el exclusivo de cada uno choca con el compartido del otro.
- Un usuario borra su cuenta mientras le aceptan una oferta: el borrado bloquea primero sus ofertas y después al usuario, así que gana quien coja antes el lock de la oferta (ver «Borrado de cuenta»).

La fila de `users` hace de mutex por usuario porque siempre existe. Además, cada fila de `user_stickers` que se va a modificar se bloquea; si es un cromo que el usuario aún no tiene, se crea vacía y se bloquea en el mismo paso (`INSERT … ON DUPLICATE KEY UPDATE`), para que dos aceptaciones que le dan el mismo cromo nuevo no pierdan una copia. El orden fijo evita deadlocks y, si MySQL detecta uno igualmente, la transacción se reintenta (3 intentos). MySQL corre con `innodb_lock_wait_timeout=3` (por defecto son 50 s) para que, bajo contención, un worker no se quede bloqueado esperando: una espera de más de 3 s también se reintenta como un deadlock y, si fallan los 3 intentos, la petición devuelve un 500 en vez de un código de negocio. Al aceptar esto ya no pasa por la oferta (no espera, ver arriba); sí puede pasar por la fila de un usuario o de un cromo muy disputados.

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
- **Caducidad** = Retirar una oferta vencida que el barrido aún no ha procesado devuelve `offer_expired`; el barrido libera el cromo en menos de un minuto.
- La oferta caduca exactamente a las 48 h (`expires_at`), pero el cromo se libera en el barrido, como mucho un minuto después. Para hacerlo exacto, los casos de uso caducarían las ofertas vencidas del usuario antes de comprobar sus copias.
- Un intercambio aceptado es definitivo.
- Cualquier usuario puede ver la colección de otro, para saber quién tiene repetido el cromo que busca antes de publicar su oferta.
- **Borrado de cuenta** (`DELETE /api/me`) = no se borra la fila, se anonimiza (nombre, email y contraseña) y se marca con `deleted_at`, para que la otra parte conserve su historial de intercambios; la FK `restrict` de `offers` impide además un borrado físico accidental. En la misma transacción se retiran sus ofertas abiertas liberando las reservas (las vencidas que el barrido aún no ha procesado se marcan como caducadas). Respeta el orden de bloqueo **ofertas → usuario**: si alguien acepta a la vez, gana quien coja antes el lock de la oferta. Con el lock del usuario se relee, para recoger ofertas publicadas mientras tanto. `PublishOffer` y `AcceptOffer` comprueban, ya con el lock del usuario, que ninguna cuenta implicada esté borrada (`account_deleted`); por HTTP una cuenta borrada ya ni se autentica. Su colección se conserva tal cual. No se envían avisos al autor borrado.

## Tests

- **Unit** (dominio, sin BD): ciclo de vida de la oferta e invariantes del VO inmutable `Copies` y de `StickerHolding`.
- **Feature** (HTTP + MySQL): cada regla de negocio, caducidad con `travel()`, aviso en el canal correcto y que no se emite si la operación falla.
- **Concurrencia** (`tests/Concurrency`): cada operación en un proceso hijo (`pcntl_fork`) con su propia conexión, todos arrancando tras una barrera de tiempo. Escenarios: 20 usuarios aceptando la misma oferta (gana uno; el resto recibe `offer_busy` u `offer_not_open`), aceptar una oferta que otra transacción tiene bloqueada (`offer_busy` al momento, sin esperar), un usuario con una repetida aceptando 10, 10 intercambios simultáneos de la misma pareja (también en los dos sentidos a la vez), 30 usuarios aceptando a la vez 30 ofertas distintas del mismo autor (todas salen y el autor no pierde ni gana copias), 10 publicaciones con la misma repetida, aceptar y retirar a la vez, aceptar una oferta mientras su autor borra la cuenta (o queda aceptada, o retirada con el cromo liberado) y publicar mientras se borra la cuenta (nunca queda una oferta abierta de una cuenta borrada). Además de quién gana, se comprueba que no se crean ni se destruyen cromos.

Para saber que estos tests detectan algo, se quitaron los `FOR UPDATE` y se ejecutaron: **los 5 escenarios originales fallan** (8 usuarios «ganaban» la misma oferta, la pareja hacía 10 intercambios en una semana, la misma repetida respaldaba 3 ofertas…).

`trading:race` hace lo mismo por HTTP contra la API levantada (25 peticiones simultáneas con curl multi). Crea sus propios usuarios y al terminar retira, con `WithdrawOffer`, las ofertas que quedaron sin aceptar, para no ensuciar la demo (`--keep` las conserva). En el escenario 1, los 409 se reparten entre `offer_busy` (llegaron mientras el ganador procesaba la oferta) y `offer_not_open` (llegaron después); la tabla es de antes de NOWAIT:

```
1) 25 usuarios aceptan la oferta #1          2) Un usuario con 1 repetida acepta 25 ofertas
| 200 accepted       | 1  |                  | 200 accepted      | 1  |
| 409 offer_not_open | 24 |                  | 409 no_spare_copy | 24 |
```

## Fuera de alcance / con más tiempo

1. **Avisos que no se pierden: notificaciones persistentes guardadas en la misma transacción.** Hoy el aviso se pierde en dos casos:
   - **El autor no está conectado.** El websocket solo llega a quien tiene la app abierta.
   - **Falla el encolado, o el proceso muere justo después del commit.** El intercambio queda hecho y el aviso no llega. Hoy ese fallo se registra en el log y la aceptación responde 200 igualmente: no tiene sentido devolver un 500 de un intercambio que ya está guardado. Ese aviso no llega a `failed_jobs`, porque el job nunca se creó; ahí solo acaban los que se encolaron y fallaron al enviarse a Reverb.

   Los dos se resuelven con lo mismo: una tabla `notifications` (bandeja de entrada con leídas y no leídas) en la que se guarda una fila por aviso **dentro de la misma transacción que el intercambio**. Guardarla es una escritura más del intercambio, como actualizar las copias, y esa fila hace a la vez de outbox y de bandeja de entrada. El envío va aparte y **nunca afecta al intercambio**: un worker manda las pendientes por websocket a quien esté conectado y por push móvil (FCM/APNs) a quien no. Si el envío falla (Reverb caído, push rechazado), el intercambio sigue hecho, la notificación queda pendiente y se reintenta, y en el peor caso el usuario la ve en la bandeja al abrir la app. El websocket pasa a ser una vía de entrega, no la fuente de verdad. Un aviso podría llegar dos veces, así que el cliente debe ignorar los repetidos (por id de notificación).
2. **Idempotencia en aceptar y publicar.** Con una mala conexión un `publish` repetido crea dos ofertas y un `accept` repetido devuelve un 409 confuso aunque el primero saliera bien. Con una cabecera `Idempotency-Key` guardada junto a su respuesta, un reintento devuelve el resultado original.
3. **Arquitectura protegida por herramientas.** PHPStan a nivel máximo y una regla de arquitectura (Deptrac o tests de arquitectura) que falle si `Domain` importa `Illuminate`, todo en CI con MySQL. Hoy la separación de capas depende de la disciplina.
4. **Contrato de API y de eventos / Documentación extra.** OpenAPI de la API y payloads de websocket versionados (`offer.accepted` v1), para que el backend evolucione sin romperse.
5. **Seguridad: autenticación real.** Sustituir la cabecera `X-User-Id` por tokens (Sanctum, o el proveedor de identidad de la app móvil). Es un cambio localizado: el resto del código solo usa `$request->user()`, y la autorización de los canales privados de Reverb reutiliza el mismo guard.

## Para producción con mucho tráfico

- **Runtime en AWS:** nginx + PHP-FPM u Octane detrás de un balanceador, con web, workers y scheduler como servicios separados que escalan por separado (ECS). Colas en SQS (o Redis con Horizon) y Reverb en varias réplicas con Redis pub/sub (ElastiCache).
- **Contención y locks:** el lock es por fila, así que el volumen general no le afecta; el riesgo son las filas calientes: una oferta muy disputada o un usuario muy activo. Las aceptaciones de ofertas de un autor popular ya no hacen cola en su fila (lock compartido), pero sí en la de un mismo cromo suyo: si un autor publica muchas ofertas del mismo cromo, las aceptaciones se serializan en esa fila durante el final de cada transacción. Si las métricas lo mostraran como punto caliente, el siguiente paso sería guardar las copias con incrementos relativos (`quantity = quantity - 1`) en vez de leer y escribir. Una oferta muy disputada ya no hace cola: se acepta con `FOR UPDATE NOWAIT` y quien llega mientras otro la procesa recibe `offer_busy` al momento.
- **Base de datos:** Aurora MySQL con **RDS Proxy**: cientos de workers PHP con conexiones propias agotan las de MySQL en un pico. Listado y colección desde réplicas con caché corta; tras escribir se lee de la primaria (`'sticky' => true`) para que el usuario vea su propio cambio.
- **Crecimiento de datos:** `offers` acumula ofertas cerradas y el camino crítico solo necesita las abiertas. Archivaría o particionaría las cerradas, sacaría el historial a una tabla `trades` con índice por pareja y fecha (el conteo semanal sigue siendo barato y mantiene la ventana móvil), y haría el barrido de caducadas en lotes con `FOR UPDATE SKIP LOCKED` y varios workers.
- **Observabilidad y validación:** Sentry para errores; Horizon para las colas y Laravel Pulse con los recolectores de Reverb para los websockets (conexiones activas, mensajes). Métricas de esperas de lock, deadlocks, reintentos, tamaño de cola y latencia del aviso (aceptación → entrega), con alertas; logs correlados por `offer_id`. Antes de un lanzamiento, pruebas de carga (k6) con escenarios de contención real, como miles de usuarios sobre las mismas ofertas, y no solo de volumen.

## Uso de la IA

He trabajado con Claude como pareja de programación, por pasos pequeños y con un commit por paso:

1. **Enfoque primero:** a partir del enunciado fijé la arquitectura (hexagonal ligera), la estrategia de concurrencia (bloqueo pesimista con orden fijo) y el tiempo real (Reverb con cola) antes de generar código.
2. **Construcción por capas:** dominio, casos de uso, infraestructura, API, tiempo real y Docker, en ese orden. La IA generaba el código y los tests de cada capa; revisando el diff antes de seguir.
3. **Verificar, no confiar:** tests contra MySQL real, tests de concurrencia validados quitando los locks a propósito, y la primera ejecución real en Docker hecha por mí.
4. **Ajustes propios:** después de la primera versión hice los refactors que se describen abajo (`Copies`, FormRequests, `OpenOffersQuery`, borrado de cuenta).

**Decisiones de diseño que revisé y ajusté**

- **Lock compartido del autor al aceptar:** en la primera versión, aceptar bloqueaba en exclusiva a los dos usuarios. Si 30 personas aceptaban a la vez 30 ofertas distintas de un mismo autor, iban de una en una porque todas esperaban por la fila de ese autor, aunque cada una tocara cromos distintos. Ahora el autor se bloquea en modo compartido, que no hace esperar a otras aceptaciones, y lo que se bloquea en exclusiva son solo sus filas de copias que cambian. Así, las 30 aceptaciones van en paralelo y solo esperan entre sí las que tocan el mismo cromo del autor. Si el cromo que recibe todavía no tiene fila, `INSERT … ON DUPLICATE KEY UPDATE` la crea y la bloquea en el mismo paso, para que dos aceptaciones que le dan un cromo nuevo no pierdan una copia. Lo comprueba un test con 30 aceptaciones simultáneas al mismo autor.
- **Aceptar sin hacer cola (`NOWAIT`):** en la primera versión, si muchas personas aceptaban la misma oferta a la vez, todas esperaban en cola por su fila aunque solo una podía ganar. Con miles a la vez, las últimas superaban el timeout del lock, se reintentaban ocupando un worker hasta unos 9 s y acababan en un 500. Ahora aceptar bloquea la oferta con `FOR UPDATE NOWAIT`: si otra petición ya la tiene, responde al momento con `409 offer_busy` en vez de esperar. El usuario sabe enseguida que alguien se le ha adelantado y no se ocupan workers esperando. Retirar, caducar y borrar la cuenta siguen esperando el lock normal, porque deben completarse.
- **VO `Copies` inmutable:** la primera versión tenía la regla de copias dentro de `StickerHolding`, que es mutable. La extraje a un value object inmutable para que la regla central (no perder la última copia ni usar una reservada) viva en un solo sitio y cada operación devuelva un estado nuevo.
- **VOs de identidad, descartados a propósito:** los ids son `int`. Valoré `UserId`, `StickerId` y `OfferId`, pero en un dominio tan pequeño añadían ceremonia en todas las capas sin ninguna regla nueva. Es lo primero que añadiría si el dominio creciera.
- **DTOs solo donde aportan:** los casos de uso de escritura reciben entre uno y tres ids con argumentos con nombre, así que un command por caso de uso sería ceremonia. Sí agrupé los filtros del listado en `OpenOffersQuery`, que tiene cinco parámetros opcionales, y moví la validación HTTP a FormRequests para dejar los controladores finos.
- **Varios agregados en una transacción:** `AcceptOffer` modifica la oferta y cuatro holdings a la vez, lo que rompe la regla de «un agregado por transacción». Lo mantuve porque el intercambio tiene que ser atómico; con consistencia eventual habría una ventana con un cromo entregado y el otro no.
- **Puerto `Accounts` en vez de reutilizar `UserLock`:** para el borrado de cuenta, Trading necesita saber si una cuenta está borrada. No metí esa regla en el lock (mezclaría un mecanismo con una regla de negocio) ni creé un agregado `Account` sin invariantes: un puerto pequeño con lo que Trading usa.
- **Sin Eloquent en el dominio:** las entidades son PHP puro y los repositorios mapean con query builder, para no tener dos modelos de la misma tabla y dejar los locks a la vista.