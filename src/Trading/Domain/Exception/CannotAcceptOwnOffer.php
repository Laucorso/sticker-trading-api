<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class CannotAcceptOwnOffer extends TradingException
{
    public function __construct()
    {
        parent::__construct('No puedes aceptar tu propia oferta');
    }

    public function errorCode(): string
    {
        return 'cannot_accept_own_offer';
    }
}
