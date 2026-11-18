<?php

declare(strict_types=1);

namespace Trading\Domain\Offer;

use DateTimeImmutable;

final readonly class OfferAccepted
{
    public function __construct(
        public int $offerId,
        public int $authorId,
        public int $acceptorId,
        public int $offeredStickerId,
        public int $requestedStickerId,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
