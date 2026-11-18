<?php

declare(strict_types=1);

namespace Trading\Domain\Offer;

final readonly class OfferExpired
{
    public function __construct(
        public int $offerId,
        public int $authorId,
    ) {}
}
