<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Broadcasting;

use Illuminate\Contracts\Events\Dispatcher;
use Shared\Application\EventPublisher;
use Trading\Domain\Offer\OfferAccepted;
use Trading\Domain\Offer\OfferExpired;

/**
 * Traduce eventos de dominio a eventos de Laravel que se emiten por websocket.
 *
 * Se llama después del commit: si encolar un aviso falla, el intercambio ya está
 * hecho. Por eso el fallo se registra y no se propaga; si no, el cliente recibiría
 * un 500 de algo que ha salido bien (y al reintentar, offer_not_open).
 */
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

            if ($notification === null) {
                continue;
            }

            try {
                $this->dispatcher->dispatch($notification);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
