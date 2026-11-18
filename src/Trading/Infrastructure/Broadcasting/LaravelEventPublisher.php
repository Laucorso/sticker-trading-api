<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Broadcasting;

use Illuminate\Contracts\Events\Dispatcher;
use Shared\Application\EventPublisher;
use Trading\Domain\Offer\OfferAccepted;
use Trading\Domain\Offer\OfferExpired;

/** Traduce eventos de dominio a eventos de Laravel que se emiten por websocket. */
final readonly class LaravelEventPublisher implements EventPublisher
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function publish(object ...$events): void
    {
        foreach ($events as $event) {
            $notification = match (true) {
                $event instanceof OfferAccepted => new OfferAcceptedNotification($event),
                $event instanceof OfferExpired => new OfferExpiredNotification($event),
                default => null,
            };

            if ($notification !== null) {
                $this->dispatcher->dispatch($notification);
            }
        }
    }
}
