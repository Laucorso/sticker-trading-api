<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

use Trading\Domain\Offer\OfferStatus;

final class OfferNotOpen extends TradingException
{
    public function __construct(public readonly OfferStatus $status)
    {
        parent::__construct("La oferta ya no está abierta (estado: {$status->value})");
    }

    public function errorCode(): string
    {
        return 'offer_not_open';
    }
}
