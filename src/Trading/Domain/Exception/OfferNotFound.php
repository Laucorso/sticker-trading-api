<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class OfferNotFound extends TradingException
{
    public function __construct(int $offerId)
    {
        parent::__construct("La oferta {$offerId} no existe");
    }

    public function errorCode(): string
    {
        return 'offer_not_found';
    }
}
