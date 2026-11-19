<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Trading\Application\Port\UserLock;

/**
 * SELECT ... FOR UPDATE sobre las filas de users, ordenadas por id.
 *
 * El orden fijo evita el deadlock clásico (A bloquea 1 y espera a 2 mientras B
 * bloquea 2 y espera a 1). Se usa la fila de users como mutex porque siempre existe.
 */
final readonly class DbUserLock implements UserLock
{
    public function __construct(private ConnectionInterface $db) {}

    public function lock(int ...$userIds): void
    {
        $ids = array_values(array_unique($userIds));
        sort($ids);

        $locked = $this->db->table('users')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($locked->count() !== count($ids)) {
            throw new \RuntimeException('Usuario inexistente: '.implode(',', array_diff($ids, $locked->all())));
        }
    }

    public function lockForTrade(int $authorId, int $acceptorId): void
    {
        $ids = [$authorId, $acceptorId];
        sort($ids);

        foreach ($ids as $id) {
            $query = $this->db->table('users')->where('id', $id);
            $query = $id === $acceptorId ? $query->lockForUpdate() : $query->sharedLock();

            if ($query->value('id') === null) {
                throw new \RuntimeException("Usuario inexistente: {$id}");
            }
        }
    }
}
