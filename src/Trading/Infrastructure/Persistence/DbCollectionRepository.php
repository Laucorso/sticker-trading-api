<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Collection\StickerHolding;

/**
 * Escribe valores absolutos (no incrementos): es correcto porque holdingOf() deja
 * la fila bloqueada hasta el final de la transacción.
 */
final readonly class DbCollectionRepository implements CollectionRepository
{
    private const TABLE = 'user_stickers';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Crea la fila vacía si no existe y la bloquea en el mismo paso: ON DUPLICATE KEY
     * toma un lock exclusivo sobre la fila que ya existía. Así, dos aceptaciones que
     * dan al mismo autor un cromo que no tenía no lo insertan las dos ni pierden una
     * copia. Si la transacción falla, el rollback quita la fila vacía; si llega a
     * guardarse con 0 copias, save() la borra.
     */
    public function holdingOf(int $userId, int $stickerId): StickerHolding
    {
        $now = CarbonImmutable::now('UTC');

        $this->db->statement(
            'INSERT INTO '.self::TABLE.' (user_id, sticker_id, quantity, reserved, created_at, updated_at)
             VALUES (?, ?, 0, 0, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity',
            [$userId, $stickerId, $now, $now],
        );

        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('sticker_id', $stickerId)
            ->first(['quantity', 'reserved']);

        return new StickerHolding($userId, $stickerId, (int) $row->quantity, (int) $row->reserved);
    }

    public function save(StickerHolding $holding): void
    {
        $key = ['user_id' => $holding->userId(), 'sticker_id' => $holding->stickerId()];

        if ($holding->quantity() === 0) {
            $this->db->table(self::TABLE)->where($key)->delete();

            return;
        }

        $now = CarbonImmutable::now('UTC');
        $this->db->table(self::TABLE)->upsert(
            [$key + ['quantity' => $holding->quantity(), 'reserved' => $holding->reserved(), 'created_at' => $now, 'updated_at' => $now]],
            ['user_id', 'sticker_id'],
            ['quantity', 'reserved', 'updated_at'],
        );
    }
}
