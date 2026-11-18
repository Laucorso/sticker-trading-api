<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Trading\Application\Query\TradingReadModel;
use Trading\Domain\Collection\StickerHolding;
use Trading\Domain\Offer\OfferStatus;

final readonly class DbTradingReadModel implements TradingReadModel
{
    public function __construct(private ConnectionInterface $db) {}

    public function openOffers(?int $offeredStickerId, ?int $requestedStickerId, ?int $authorId, int $perPage, ?string $cursor): array
    {
        $page = $this->db->table('offers as o')
            ->join('users as u', 'u.id', '=', 'o.author_id')
            ->join('stickers as so', 'so.id', '=', 'o.offered_sticker_id')
            ->join('stickers as sr', 'sr.id', '=', 'o.requested_sticker_id')
            ->where('o.status', OfferStatus::Open->value)
            // Una oferta vencida que el job aún no ha barrido ya no se muestra.
            ->where('o.expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->when($offeredStickerId, fn ($q) => $q->where('o.offered_sticker_id', $offeredStickerId))
            ->when($requestedStickerId, fn ($q) => $q->where('o.requested_sticker_id', $requestedStickerId))
            ->when($authorId, fn ($q) => $q->where('o.author_id', $authorId))
            ->orderByDesc('o.id')
            ->select([
                'o.id', 'o.author_id', 'u.name as author_name',
                'o.offered_sticker_id', 'so.name as offered_sticker_name',
                'o.requested_sticker_id', 'sr.name as requested_sticker_name',
                'o.status', 'o.created_at', 'o.expires_at',
            ])
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'data' => collect($page->items())->map(fn (object $row) => [
                'id' => (int) $row->id,
                'author' => ['id' => (int) $row->author_id, 'name' => $row->author_name],
                'offered_sticker' => ['id' => (int) $row->offered_sticker_id, 'name' => $row->offered_sticker_name],
                'requested_sticker' => ['id' => (int) $row->requested_sticker_id, 'name' => $row->requested_sticker_name],
                'status' => $row->status,
                'created_at' => $this->iso($row->created_at),
                'expires_at' => $this->iso($row->expires_at),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    public function collectionOf(int $userId): array
    {
        return $this->db->table('user_stickers as us')
            ->join('stickers as s', 's.id', '=', 'us.sticker_id')
            ->where('us.user_id', $userId)
            ->orderBy('us.sticker_id')
            ->get(['us.sticker_id', 's.name', 'us.quantity', 'us.reserved'])
            ->map(fn (object $row) => [
                'sticker_id' => (int) $row->sticker_id,
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'reserved' => (int) $row->reserved,
                // Misma regla que usa el dominio, sin duplicarla en SQL.
                'tradeable' => (new StickerHolding($userId, (int) $row->sticker_id, (int) $row->quantity, (int) $row->reserved))->spareCopies(),
            ])
            ->all();
    }

    public function stickers(): array
    {
        return $this->db->table('stickers')->orderBy('id')->get(['id', 'name'])
            ->map(fn (object $row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();
    }

    private function iso(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString();
    }
}
