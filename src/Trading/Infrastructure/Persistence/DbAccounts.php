<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Trading\Application\Port\Accounts;
use Trading\Domain\Exception\AccountDeleted;

/**
 * SELECT normales: quien llama ya tiene el lock del usuario y, en READ COMMITTED,
 * cada lectura ve lo último confirmado (un borrado recién hecho incluido).
 */
final readonly class DbAccounts implements Accounts
{
    private const TABLE = 'users';

    public function __construct(private ConnectionInterface $db) {}

    public function ensureActive(int ...$userIds): void
    {
        $deleted = $this->db->table(self::TABLE)
            ->whereIn('id', $userIds)
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        if ($deleted !== null) {
            throw new AccountDeleted((int) $deleted);
        }
    }

    public function isDeleted(int $userId): bool
    {
        return $this->db->table(self::TABLE)
            ->where('id', $userId)
            ->whereNotNull('deleted_at')
            ->exists();
    }

    public function anonymize(int $userId, DateTimeImmutable $now): void
    {
        $this->db->table(self::TABLE)->where('id', $userId)->update([
            'name' => 'Usuario eliminado',
            'email' => "deleted-{$userId}@invalid",
            'password' => '',
            'remember_token' => null,
            'email_verified_at' => null,
            'deleted_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
