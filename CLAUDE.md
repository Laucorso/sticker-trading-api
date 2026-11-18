# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

API en Laravel 13 (PHP 8.3 en Docker) + MySQL 8.4 para intercambiar cromos repetidos, con avisos en tiempo real por Reverb. El `README.md` explica las decisiones de diseño y las suposiciones de negocio; léelo antes de cambiar reglas o concurrencia.

## Comandos

Todo corre dentro de Docker: los tests necesitan MySQL real (no hay SQLite) y `vendor/` se instala en el contenedor al arrancar.

```bash
docker compose up --build -d                                         # API + demo en :8000, Reverb en :8081 del host
docker compose exec app php artisan test                             # toda la suite
docker compose exec app php artisan test --testsuite=Unit            # Unit | Feature | Concurrency
docker compose exec app php artisan test --filter=DeleteAccountTest  # un archivo o un método
docker compose exec app vendor/bin/pint                              # formato (pint --test para solo comprobar)
docker compose exec app php artisan trading:race                     # carrera por HTTP contra la API levantada
docker compose exec app php artisan migrate:fresh --seed             # resetear los datos de la demo
```

Los tests usan la BD `trading_test` (`phpunit.xml`); los de concurrencia necesitan `pcntl` (lo tiene la imagen de Docker; en otro entorno se saltan).

## Arquitectura

Hexagonal ligera, con autoload PSR-4 propio fuera de `app/`:

- `src/Trading/Domain`: PHP puro, sin Laravel. `Offer` (ciclo de vida open → accepted | withdrawn | expired), `StickerHolding` (entidad) con el VO inmutable `Copies` (invariante: nunca se compromete la última copia), `WeeklyTradeLimit`, excepciones.
- `src/Trading/Application`: un caso de uso invocable por clase (`PublishOffer`, `AcceptOffer`, `WithdrawOffer`, `ExpireDueOffers`, `DeleteAccount`) y los puertos (`Port/UserLock`, `Port/Accounts`, `Query/TradingReadModel` + DTO `OpenOffersQuery`).
- `src/Trading/Infrastructure`: adaptadores. Los repositorios usan el query builder (no Eloquent). Los bindings puerto → adaptador están solo en `TradingServiceProvider`.
- `src/Shared`: `Clock`, `TransactionManager`, `EventPublisher`.
- `app/`: solo lo propio de Laravel (modelo `User` para la autenticación, `AppServiceProvider` con el guard simulado `X-User-Id`, comando `trading:race`).

Lado de lectura (listado, colección): `DbTradingReadModel` va directo a SQL y devuelve arrays; no pasa por entidades.

HTTP: las FormRequest (`Infrastructure/Http/Requests`) validan solo la forma y entregan primitivos o un DTO (`toQuery()`). Las reglas de negocio (mismo cromo, repetidas, límite semanal) las aplica el dominio, no la validación: si se duplican, cambia el código de error que espera el cliente.

### Concurrencia: lo que hay que respetar al tocar un caso de uso

- Cada caso de uso de escritura corre dentro de `TransactionManager::run()`, que reintenta la closure entera ante deadlock (3 intentos). Todo lo que se lee y se escribe debe ir dentro de la closure.
- **Orden de bloqueo fijo: oferta(s) por id → usuarios por id** (`UserLock`, `SELECT … FOR UPDATE` sobre `users`). Romper el orden provoca deadlocks.
- Cualquier operación que modifique la colección de un usuario o cuente sus intercambios bloquea antes a ese usuario y, con el lock tomado, comprueba `Accounts::ensureActive()`.
- Aislamiento `READ COMMITTED` (`config/database.php`): tras bloquear se lee con SELECT normales y se ve lo último confirmado. Un `FOR UPDATE` por rango no bloquea huecos, así que lo insertado por otra transacción solo se ve al volver a leer (por eso `DeleteAccount` relee las ofertas con el lock del usuario).
- `user_stickers.reserved` es un contador con un CHECK en la BD como red de seguridad; el mecanismo real son los locks.
- Los eventos de dominio se publican **después** del commit (fuera de la closure). `LaravelEventPublisher` los traduce a notificaciones `ShouldBroadcast` que envía el worker `queue`.
- La caducidad la decide `expires_at`, no el barrido: una oferta vencida ya no se puede aceptar aunque siga `open` en BD. `offers:expire` (cada minuto) solo libera reservas.

### Errores

Las violaciones de reglas extienden `TradingException` con un `errorCode()` estable. `TradingExceptionRenderer` decide el status (409 por defecto; el mapa `STATUS` fija 404, 403 y 422). Una excepción nueva que no deba ser 409 se añade a ese mapa.

## Tests

- `tests/Unit`: dominio sin BD.
- `tests/Feature`: HTTP + MySQL con `RefreshDatabase`, el trait `Tests\Support\TradingFixtures` (`createUser`, `giveStickers`, `holding`, `as()` para la cabecera) y `travel()` para el tiempo. `Tests\TestCase::call()` olvida los guards en cada petición para que mande la cabecera `X-User-Id` de cada llamada.
- `tests/Concurrency`: `ConcurrencyTestCase::runConcurrently()` ejecuta cada closure en un proceso hijo (`pcntl_fork`) con su propia conexión y devuelve `'ok'` o el `errorCode()` de cada una. Usa `DatabaseMigrations`, no `RefreshDatabase` (los hijos no verían una transacción sin confirmar). Al añadir una regla con riesgo de carrera, añade aquí un escenario que compruebe también que no se crean ni se destruyen cromos.

## Convenciones

- Comentarios, mensajes de excepción, README y commits en español. Commits estilo Conventional Commits con scope (`feat(account): …`, `refactor(domain): …`).
- `declare(strict_types=1)` y clases `final` (`readonly` cuando no hay estado mutable).

## Entorno

- Reverb se publica en el 8081 del host porque el 8080 suele estar ocupado; dentro de Docker sigue en 8080. El navegador lo toma de `REVERB_PUBLIC_PORT`.
- Si `/up` devuelve 500 con «Please provide a valid cache path», faltan los directorios de `storage/framework` (se crean con sus `.gitignore` versionados; no borrarlos).