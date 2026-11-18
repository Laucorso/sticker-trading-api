<?php

declare(strict_types=1);

namespace Trading\Application;

use Shared\Application\EventPublisher;
use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Trading\Application\Port\UserLock;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Offer\OfferExpired;
use Trading\Domain\Offer\OfferRepository;

/**
 * Caduca las ofertas vencidas y libera el cromo reservado.
 *
 * Una transacción corta por oferta (no una gigante por lote) para no retener locks
 * de muchos usuarios a la vez. Es idempotente: si una oferta se acepta o se retira
 * mientras tanto, Offer::expire() devuelve false y no se toca nada.
 */
final readonly class ExpireDueOffers
{
    public function __construct(
        private TransactionManager $transactions,
        private UserLock $userLock,
        private OfferRepository $offers,
        private CollectionRepository $collections,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    /** @return int ofertas caducadas */
    public function __invoke(int $batchSize = 500): int
    {
        $expired = 0;

        foreach ($this->offers->idsDueForExpiration($this->clock->now(), $batchSize) as $offerId) {
            $event = $this->transactions->run(function () use ($offerId): ?OfferExpired {
                $offer = $this->offers->findForUpdate($offerId);

                if ($offer === null || ! $offer->expire($this->clock->now())) {
                    return null;
                }

                $this->userLock->lock($offer->authorId());
                $holding = $this->collections->holdingOf($offer->authorId(), $offer->offeredStickerId());
                $holding->releaseReservation();
                $this->collections->save($holding);

                $this->offers->update($offer);

                return new OfferExpired($offerId, $offer->authorId());
            });

            if ($event !== null) {
                $this->events->publish($event);
                $expired++;
            }
        }

        return $expired;
    }
}
