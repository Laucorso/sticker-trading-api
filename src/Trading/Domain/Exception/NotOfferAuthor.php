<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class NotOfferAuthor extends TradingException
{
    public function __construct()
    {
        parent::__construct('Solo el autor puede retirar la oferta');
    }

    public function errorCode(): string
    {
        return 'not_offer_author';
    }
}
