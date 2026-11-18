<?php

declare(strict_types=1);

namespace Trading\Application;

use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Trading\Application\Port\Accounts;
use Trading\Application\Port\UserLock;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferRepository;

/**
 * Borra la cuenta: cierra sus ofertas abiertas liberando las reservas y anonimiza
 * al usuario en vez de borrarlo, para conservar el historial de la otra parte.
 *
 * Respeta el orden de bloqueo de siempre (ofertas por id -> usuario):
 * - Contra AcceptOffer/WithdrawOffer de una de sus ofertas: quien coge antes el lock
 *   de la oferta gana. Si gana el borrado, el otro la ve retirada (OfferNotOpen);
 *   si gana la aceptación, el borrado la ve aceptada y no la toca.
 * - Contra PublishOffer: en READ COMMITTED el primer FOR UPDATE no bloquea huecos,
 *   así que una publicación que ya tenía el lock del usuario puede insertar una
 *   oferta que esa lectura no vio. Por eso, ya con el lock del usuario, se vuelve a
 *   leer: cualquier PublishOffer anterior ha confirmado y los posteriores verán la
 *   cuenta borrada (AccountDeleted).
 *
 * Idempotente: borrar una cuenta ya borrada no hace nada.
 */
final readonly class DeleteAccount
{
    public function __construct(
        private TransactionManager $transactions,
        private UserLock $userLock,
        private OfferRepository $offers,
        private CollectionRepository $collections,
        private Accounts $accounts,
        private Clock $clock,
    ) {}

    public function __invoke(int $userId): void
    {
        $this->transactions->run(function () use ($userId): void {
            $this->offers->openByAuthorForUpdate($userId);
            $this->userLock->lock($userId);

            if ($this->accounts->isDeleted($userId)) {
                return;
            }

            $now = $this->clock->now();

            // Relectura con el lock del usuario: incluye las publicadas mientras esperábamos.
            foreach ($this->offers->openByAuthorForUpdate($userId) as $offer) {
                $this->close($offer, $now);
            }

            $this->accounts->anonymize($userId, $now);
        });
    }

    /**
     * Una oferta vencida que el barrido aún no ha caducado se marca como caducada
     * (withdraw() la rechazaría); el resto se retira. En los dos casos se libera la copia.
     */
    private function close(Offer $offer, \DateTimeImmutable $now): void
    {
        if (! $offer->expire($now)) {
            $offer->withdraw($offer->authorId(), $now);
        }

        $holding = $this->collections->holdingOf($offer->authorId(), $offer->offeredStickerId());
        $holding->releaseReservation();
        $this->collections->save($holding);

        $this->offers->update($offer);
    }
}
