<?php

declare(strict_types=1);

namespace Trading\Application;

use Shared\Application\EventPublisher;
use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Trading\Application\Port\Accounts;
use Trading\Application\Port\UserLock;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Collection\StickerHolding;
use Trading\Domain\Exception\OfferNotFound;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferAccepted;
use Trading\Domain\Offer\OfferRepository;
use Trading\Domain\Trade\WeeklyTradeLimit;

/**
 * Orden de bloqueo: oferta -> usuarios (por id) -> copias. Quien acepta se bloquea en
 * exclusiva y el autor en compartido.
 *
 * - Muchos usuarios aceptando la misma oferta: la oferta se bloquea con NOWAIT, así
 *   que quien llega mientras otro la procesa recibe OfferBusy al momento en vez de
 *   hacer cola (y de acabar en timeout en un pico); quien llega después del commit
 *   la ve aceptada (OfferNotOpen). Retirar, caducar y borrar la cuenta sí esperan.
 * - El mismo usuario aceptando varias ofertas: espera a su propio lock de usuario
 *   y, cuando entra, ve sus copias ya descontadas (NoSpareCopy).
 * - Muchos usuarios aceptando ofertas distintas del mismo autor: el lock compartido
 *   del autor no los pone en cola; solo coinciden en las filas de cromos del autor
 *   que tocan a la vez, que se bloquean por orden de cromo.
 * - La misma pareja a la vez, en cualquier sentido: el lock exclusivo de quien acepta
 *   choca con el compartido del autor, así que el conteo semanal se serializa.
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
            $offer = $this->offers->findForUpdateNoWait($offerId) ?? throw new OfferNotFound($offerId);
            $now = $this->clock->now();

            $accepted = $offer->accept($acceptorId, $now);

            $this->userLock->lockForTrade($offer->authorId(), $acceptorId);
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
        $author = $this->lockHoldings($trade->authorId, $trade->offeredStickerId, $trade->requestedStickerId);
        $acceptor = $this->lockHoldings($trade->acceptorId, $trade->offeredStickerId, $trade->requestedStickerId);

        $author[$trade->offeredStickerId]->handOverReserved();
        $acceptor[$trade->requestedStickerId]->handOverSpare();
        $author[$trade->requestedStickerId]->receive();
        $acceptor[$trade->offeredStickerId]->receive();

        foreach ([...array_values($author), ...array_values($acceptor)] as $holding) {
            $this->collections->save($holding);
        }
    }

    /**
     * Por orden de cromo: varias aceptaciones de ofertas del mismo autor pueden tocar a
     * la vez sus filas, y un orden fijo evita que se bloqueen en cruz.
     *
     * @return array<int, StickerHolding> por id de cromo
     */
    private function lockHoldings(int $userId, int ...$stickerIds): array
    {
        sort($stickerIds);

        $holdings = [];
        foreach ($stickerIds as $stickerId) {
            $holdings[$stickerId] = $this->collections->holdingOf($userId, $stickerId);
        }

        return $holdings;
    }
}
