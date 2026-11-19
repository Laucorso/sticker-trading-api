<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Trading\Domain\Exception\OfferBusy;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferRepository;
use Trading\Domain\Offer\OfferStatus;

final readonly class DbOfferRepository implements OfferRepository
{
    private const TABLE = 'offers';

    private const FORMAT = 'Y-m-d H:i:s';

    /** ER_LOCK_NOWAIT: la fila está bloqueada y la consulta pidió NOWAIT. */
    private const LOCK_NOT_AVAILABLE = 3572;

    public function __construct(private ConnectionInterface $db) {}

    public function add(Offer $offer): Offer
    {
        $id = $this->db->table(self::TABLE)->insertGetId($this->toRow($offer) + [
            'created_at' => $offer->createdAt()->format(self::FORMAT),
        ]);

        return $this->hydrate($this->db->table(self::TABLE)->find($id));
    }

    public function update(Offer $offer): void
    {
        $this->db->table(self::TABLE)->where('id', $offer->id())->update($this->toRow($offer));
    }

    public function findForUpdate(int $id): ?Offer
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->lockForUpdate()->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findForUpdateNoWait(int $id): ?Offer
    {
        try {
            $row = $this->db->table(self::TABLE)->where('id', $id)->lock('for update nowait')->first();
        } catch (QueryException $e) {
            // Laravel no reintenta este error (no es un deadlock), así que no hay reintentos en cadena.
            throw ($e->errorInfo[1] ?? null) === self::LOCK_NOT_AVAILABLE ? new OfferBusy($id) : $e;
        }

        return $row === null ? null : $this->hydrate($row);
    }

    public function openByAuthorForUpdate(int $authorId): array
    {
        return $this->db->table(self::TABLE)
            ->where('author_id', $authorId)
            ->where('status', OfferStatus::Open->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (object $row) => $this->hydrate($row))
            ->all();
    }

    public function idsDueForExpiration(DateTimeImmutable $now, int $limit): array
    {
        return $this->db->table(self::TABLE)
            ->where('status', OfferStatus::Open->value)
            ->where('expires_at', '<=', $now->format(self::FORMAT))
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function countTradesBetween(int $userA, int $userB, DateTimeImmutable $since): int
    {
        return $this->db->table(self::TABLE)
            ->where('status', OfferStatus::Accepted->value)
            ->where('closed_at', '>', $since->format(self::FORMAT))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('author_id', $userA)->where('accepted_by', $userB))
                ->orWhere(fn (Builder $q) => $q->where('author_id', $userB)->where('accepted_by', $userA)))
            ->count();
    }

    /** @return array<string, mixed> */
    private function toRow(Offer $offer): array
    {
        return [
            'author_id' => $offer->authorId(),
            'offered_sticker_id' => $offer->offeredStickerId(),
            'requested_sticker_id' => $offer->requestedStickerId(),
            'status' => $offer->status()->value,
            'expires_at' => $offer->expiresAt()->format(self::FORMAT),
            'accepted_by' => $offer->acceptedBy(),
            'closed_at' => $offer->closedAt()?->format(self::FORMAT),
            'updated_at' => ($offer->closedAt() ?? $offer->createdAt())->format(self::FORMAT),
        ];
    }

    private function hydrate(object $row): Offer
    {
        return Offer::reconstitute(
            id: (int) $row->id,
            authorId: (int) $row->author_id,
            offeredStickerId: (int) $row->offered_sticker_id,
            requestedStickerId: (int) $row->requested_sticker_id,
            status: OfferStatus::from($row->status),
            createdAt: $this->date($row->created_at),
            expiresAt: $this->date($row->expires_at),
            acceptedBy: $row->accepted_by === null ? null : (int) $row->accepted_by,
            closedAt: $row->closed_at === null ? null : $this->date($row->closed_at),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
