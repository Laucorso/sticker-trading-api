<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Trading\Domain\Offer\OfferExpired;

final class OfferExpiredNotification implements ShouldBroadcast
{
    public function __construct(public readonly OfferExpired $expired) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->expired->authorId}");
    }

    public function broadcastAs(): string
    {
        return 'offer.expired';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['offer_id' => $this->expired->offerId];
    }
}
