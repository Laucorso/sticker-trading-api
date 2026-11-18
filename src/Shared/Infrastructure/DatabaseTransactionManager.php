<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Shared\Application\TransactionManager;

final readonly class DatabaseTransactionManager implements TransactionManager
{
    /**
     * Laravel reintenta la closure completa si MySQL la elige como víctima de un
     * deadlock. Los casos de uso son seguros de reintentar: todo lo que leen y
     * escriben ocurre dentro de la closure.
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private ConnectionInterface $db) {}

    public function run(callable $operation): mixed
    {
        return $this->db->transaction($operation(...), self::DEADLOCK_ATTEMPTS);
    }
}
