<?php

declare(strict_types=1);

namespace Trading\Domain\Trade;

use DateTimeImmutable;
use Trading\Domain\Exception\WeeklyTradeLimitReached;
use Trading\Domain\Offer\OfferRepository;

/**
 * Máximo de intercambios entre la misma pareja en una ventana móvil de 7 días
 * (no semana natural: así no se pueden hacer 5 el domingo y 5 el lunes).
 */
final readonly class WeeklyTradeLimit
{
    public const MAX_TRADES = 5;

    public const WINDOW = 'P7D';

    public function __construct(private OfferRepository $offers) {}

    public function ensureCanTrade(int $userA, int $userB, DateTimeImmutable $now): void
    {
        $since = $now->sub(new \DateInterval(self::WINDOW));

        if ($this->offers->countTradesBetween($userA, $userB, $since) >= self::MAX_TRADES) {
            throw new WeeklyTradeLimitReached(self::MAX_TRADES);
        }
    }
}
