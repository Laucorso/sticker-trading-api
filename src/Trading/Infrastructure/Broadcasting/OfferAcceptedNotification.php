<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Trading\Domain\Offer\OfferAccepted;

/**
 * ShouldBroadcast (y no ShouldBroadcastNow): el envío a Reverb lo hace un worker
 * de la cola, fuera de la petición. Si Reverb está caído, la aceptación no falla
 * y el job se reintenta.
 */
final class OfferAcceptedNotification implements ShouldBroadcast
{
    public int $tries = 5;

    public function __construct(public readonly OfferAccepted $trade) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->trade->authorId}");
    }

    public function broadcastAs(): string
    {
        return 'offer.accepted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'offer_id' => $this->trade->offerId,
            'accepted_by' => $this->trade->acceptorId,
            'given_sticker_id' => $this->trade->offeredStickerId,
            'received_sticker_id' => $this->trade->requestedStickerId,
            'accepted_at' => $this->trade->acceptedAt->format(DATE_ATOM),
        ];
    }
}
