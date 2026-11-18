<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Collection\StickerHolding;

/**
 * Escribe valores absolutos (no incrementos): es correcto porque quien llama tiene
 * el lock del usuario dueño de la fila durante toda la transacción.
 */
final readonly class DbCollectionRepository implements CollectionRepository
{
    private const TABLE = 'user_stickers';

    public function __construct(private ConnectionInterface $db) {}

    public function holdingOf(int $userId, int $stickerId): StickerHolding
    {
        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('sticker_id', $stickerId)
            ->first(['quantity', 'reserved']);

        return $row === null
            ? StickerHolding::empty($userId, $stickerId)
            : new StickerHolding($userId, $stickerId, (int) $row->quantity, (int) $row->reserved);
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
