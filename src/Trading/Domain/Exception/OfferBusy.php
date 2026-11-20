<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class OfferBusy extends TradingException
{
    public function __construct(int $offerId)
    {
        parent::__construct('La oferta está cambiando en este momento: alguien la está aceptando o su autor la está retirando. Puede que ya no esté disponible; vuelve a intentarlo en unos segundos.');
    }

    public function errorCode(): string
    {
        return 'offer_busy';
    }
}
