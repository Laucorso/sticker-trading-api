<?php

declare(strict_types=1);

namespace Trading\Application;

use Shared\Application\EventPublisher;
use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Trading\Application\Port\Accounts;
use Trading\Application\Port\UserLock;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Exception\OfferNotFound;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferAccepted;
use Trading\Domain\Offer\OfferRepository;
use Trading\Domain\Trade\WeeklyTradeLimit;

/**
 * Orden de bloqueo: oferta -> usuarios (por id). Lo comparten todos los casos de uso.
 *
 * - Dos usuarios aceptando la misma oferta: el segundo espera al lock de la oferta
 *   y, cuando entra, la ve aceptada (OfferNotOpen).
 * - El mismo usuario aceptando varias ofertas: espera a su propio lock de usuario
 *   y, cuando entra, ve sus copias ya descontadas (NoSpareCopy).
 * - La misma pareja a la vez: el lock de los dos usuarios serializa el conteo semanal.
 * - Si una de las dos cuentas se borra a la vez: con el lock de los usuarios se
 *   comprueba que ambas siguen activas (AccountDeleted).
 */
final readonly class AcceptOffer
{
    public function __construct(
        private TransactionManager $transactions,
        private UserLock $userLock,
        private OfferRepository $offers,
        private CollectionRepository $collections,
        private WeeklyTradeLimit $tradeLimit,
        private Accounts $accounts,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    public function __invoke(int $offerId, int $acceptorId): Offer
    {
        [$offer, $accepted] = $this->transactions->run(function () use ($offerId, $acceptorId): array {
            $offer = $this->offers->findForUpdate($offerId) ?? throw new OfferNotFound($offerId);
            $now = $this->clock->now();

            $accepted = $offer->accept($acceptorId, $now);

            $this->userLock->lock($offer->authorId(), $acceptorId);
            $this->accounts->ensureActive($offer->authorId(), $acceptorId);
            $this->tradeLimit->ensureCanTrade($offer->authorId(), $acceptorId, $now);
            $this->swapStickers($accepted);

            $this->offers->update($offer);

            return [$offer, $accepted];
        });

        $this->events->publish($accepted);

        return $offer;
    }

    private function swapStickers(OfferAccepted $trade): void
    {
        $authorGives = $this->collections->holdingOf($trade->authorId, $trade->offeredStickerId);
        $acceptorGives = $this->collections->holdingOf($trade->acceptorId, $trade->requestedStickerId);
        $authorGets = $this->collections->holdingOf($trade->authorId, $trade->requestedStickerId);
        $acceptorGets = $this->collections->holdingOf($trade->acceptorId, $trade->offeredStickerId);

        $authorGives->handOverReserved();
        $acceptorGives->handOverSpare();
        $authorGets->receive();
        $acceptorGets->receive();

        foreach ([$authorGives, $acceptorGives, $authorGets, $acceptorGets] as $holding) {
            $this->collections->save($holding);
        }
    }
}
