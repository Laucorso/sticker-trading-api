<?php

declare(strict_types=1);

namespace Trading\Application\Port;

/**
 * Exclusión mutua por usuario dentro de la transacción en curso.
 *
 * Todo caso de uso que modifica la colección de un usuario o cuenta sus
 * intercambios bloquea antes a ese usuario. Así dos peticiones simultáneas del
 * mismo usuario (o de la misma pareja) se ejecutan una detrás de otra y las
 * comprobaciones "tiene repetidas" / "lleva menos de 5" no se quedan obsoletas.
 * La implementación debe bloquear siempre en el mismo orden para evitar deadlocks.
 */
interface UserLock
{
    public function lock(int ...$userIds): void;
}
