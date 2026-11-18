<?php

declare(strict_types=1);

namespace Trading\Domain\Offer;

use DateTimeImmutable;

interface OfferRepository
{
    /** Persiste una oferta nueva y la devuelve con su id. */
    public function add(Offer $offer): Offer;

    public function update(Offer $offer): void;

    /**
     * Devuelve la oferta bloqueada para escritura hasta el final de la transacción.
     * Es lo que serializa varias aceptaciones/retiradas sobre la misma oferta.
     */
    public function findForUpdate(int $id): ?Offer;

    /** @return list<int> */
    public function idsDueForExpiration(DateTimeImmutable $now, int $limit): array;

    /** Intercambios cerrados entre la pareja (en cualquier dirección) después de $since. */
    public function countTradesBetween(int $userA, int $userB, DateTimeImmutable $since): int;
}
