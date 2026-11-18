<?php

declare(strict_types=1);

namespace Trading\Domain\Offer;

use DateTimeImmutable;
use Trading\Domain\Exception\CannotAcceptOwnOffer;
use Trading\Domain\Exception\NotOfferAuthor;
use Trading\Domain\Exception\OfferAlreadyExpired;
use Trading\Domain\Exception\OfferNotOpen;
use Trading\Domain\Exception\SameStickerOffer;

/**
 * «Doy el cromo offered a cambio del cromo requested».
 *
 * La entidad solo conoce su ciclo de vida (open → accepted | withdrawn | expired).
 * Que el autor tenga copias repetidas o el límite semanal se validan en el caso de
 * uso, que es quien tiene acceso a las colecciones.
 */
final class Offer
{
    public const TTL = 'PT48H';

    private function __construct(
        private readonly ?int $id,
        private readonly int $authorId,
        private readonly int $offeredStickerId,
        private readonly int $requestedStickerId,
        private OfferStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private ?int $acceptedBy = null,
        private ?DateTimeImmutable $closedAt = null,
    ) {}

    public static function publish(int $authorId, int $offeredStickerId, int $requestedStickerId, DateTimeImmutable $now): self
    {
        if ($offeredStickerId === $requestedStickerId) {
            throw new SameStickerOffer;
        }

        return new self(
            id: null,
            authorId: $authorId,
            offeredStickerId: $offeredStickerId,
            requestedStickerId: $requestedStickerId,
            status: OfferStatus::Open,
            createdAt: $now,
            expiresAt: $now->add(new \DateInterval(self::TTL)),
        );
    }

    /** Solo para la capa de persistencia. */
    public static function reconstitute(
        int $id,
        int $authorId,
        int $offeredStickerId,
        int $requestedStickerId,
        OfferStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?int $acceptedBy,
        ?DateTimeImmutable $closedAt,
    ): self {
        return new self($id, $authorId, $offeredStickerId, $requestedStickerId, $status, $createdAt, $expiresAt, $acceptedBy, $closedAt);
    }

    public function accept(int $acceptorId, DateTimeImmutable $now): OfferAccepted
    {
        $this->ensureOpen($now);

        if ($acceptorId === $this->authorId) {
            throw new CannotAcceptOwnOffer;
        }

        $this->status = OfferStatus::Accepted;
        $this->acceptedBy = $acceptorId;
        $this->closedAt = $now;

        return new OfferAccepted(
            offerId: (int) $this->id,
            authorId: $this->authorId,
            acceptorId: $acceptorId,
            offeredStickerId: $this->offeredStickerId,
            requestedStickerId: $this->requestedStickerId,
            acceptedAt: $now,
        );
    }

    public function withdraw(int $requesterId, DateTimeImmutable $now): void
    {
        if ($requesterId !== $this->authorId) {
            throw new NotOfferAuthor;
        }

        $this->ensureOpen($now);

        $this->status = OfferStatus::Withdrawn;
        $this->closedAt = $now;
    }

    /**
     * Idempotente: devuelve false si no había nada que caducar (ya cerrada o aún vigente),
     * para que el proceso de caducidad pueda reintentarse sin efectos secundarios.
     */
    public function expire(DateTimeImmutable $now): bool
    {
        if ($this->status !== OfferStatus::Open || ! $this->hasExpiredAt($now)) {
            return false;
        }

        $this->status = OfferStatus::Expired;
        $this->closedAt = $now;

        return true;
    }

    public function hasExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    private function ensureOpen(DateTimeImmutable $now): void
    {
        if ($this->status !== OfferStatus::Open) {
            throw new OfferNotOpen($this->status);
        }

        // La caducidad real la marca expiresAt, no el job: si el job va con retraso,
        // la oferta ya no se puede usar aunque en BD siga como "open".
        if ($this->hasExpiredAt($now)) {
            throw new OfferAlreadyExpired;
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function authorId(): int
    {
        return $this->authorId;
    }

    public function offeredStickerId(): int
    {
        return $this->offeredStickerId;
    }

    public function requestedStickerId(): int
    {
        return $this->requestedStickerId;
    }

    public function status(): OfferStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function acceptedBy(): ?int
    {
        return $this->acceptedBy;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }
}
