<?php

declare(strict_types=1);

namespace Trading\Application;

use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Trading\Application\Port\UserLock;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Exception\OfferNotFound;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferRepository;

final readonly class WithdrawOffer
{
    public function __construct(
        private TransactionManager $transactions,
        private UserLock $userLock,
        private OfferRepository $offers,
        private CollectionRepository $collections,
        private Clock $clock,
    ) {}

    public function __invoke(int $offerId, int $requesterId): Offer
    {
        return $this->transactions->run(function () use ($offerId, $requesterId): Offer {
            $offer = $this->offers->findForUpdate($offerId) ?? throw new OfferNotFound($offerId);

            $offer->withdraw($requesterId, $this->clock->now());

            $this->userLock->lock($offer->authorId());
            $holding = $this->collections->holdingOf($offer->authorId(), $offer->offeredStickerId());
            $holding->releaseReservation();
            $this->collections->save($holding);

            $this->offers->update($offer);

            return $offer;
        });
    }
}
