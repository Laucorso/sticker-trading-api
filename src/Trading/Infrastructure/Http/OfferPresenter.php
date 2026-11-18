<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use Trading\Domain\Offer\Offer;

final class OfferPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(Offer $offer): array
    {
        return [
            'id' => $offer->id(),
            'author_id' => $offer->authorId(),
            'offered_sticker_id' => $offer->offeredStickerId(),
            'requested_sticker_id' => $offer->requestedStickerId(),
            'status' => $offer->status()->value,
            'created_at' => $offer->createdAt()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $offer->expiresAt()->format('Y-m-d\TH:i:s\Z'),
            'accepted_by' => $offer->acceptedBy(),
            'closed_at' => $offer->closedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
