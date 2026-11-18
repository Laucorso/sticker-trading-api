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

final readonly class PublishOffer
{
    public function __construct(
        private TransactionManager $transactions,
        private UserLock $userLock,
        private OfferRepository $offers,
        private CollectionRepository $collections,
        private Accounts $accounts,
        private Clock $clock,
    ) {}

    public function __invoke(int $authorId, int $offeredStickerId, int $requestedStickerId): Offer
    {
        return $this->transactions->run(function () use ($authorId, $offeredStickerId, $requestedStickerId): Offer {
            $offer = Offer::publish($authorId, $offeredStickerId, $requestedStickerId, $this->clock->now());

            $this->userLock->lock($authorId);
            $this->accounts->ensureActive($authorId);

            $holding = $this->collections->holdingOf($authorId, $offeredStickerId);
            $holding->reserveSpare();
            $this->collections->save($holding);

            return $this->offers->add($offer);
        });
    }
}
