<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class OfferAlreadyExpired extends TradingException
{
    public function __construct()
    {
        parent::__construct('La oferta ha caducado');
    }

    public function errorCode(): string
    {
        return 'offer_expired';
    }
}
