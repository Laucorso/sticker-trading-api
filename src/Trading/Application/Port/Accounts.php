<?php

declare(strict_types=1);

namespace Trading\Application\Port;

use DateTimeImmutable;
use Trading\Domain\Exception\AccountDeleted;

/**
 * Estado de las cuentas que le importa a Trading: si siguen activas.
 *
 * Se consulta siempre con el lock del usuario ya tomado (UserLock), así que la
 * respuesta no puede quedarse obsoleta antes de que termine la transacción:
 * para borrar la cuenta hace falta ese mismo lock.
 */
interface Accounts
{
    /** @throws AccountDeleted si alguna de las cuentas está borrada */
    public function ensureActive(int ...$userIds): void;

    public function isDeleted(int $userId): bool;

    /** Borra los datos personales y marca la cuenta como eliminada. */
    public function anonymize(int $userId, DateTimeImmutable $now): void;
}
