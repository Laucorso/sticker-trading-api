<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class SameStickerOffer extends TradingException
{
    public function __construct()
    {
        parent::__construct('El cromo ofrecido y el pedido no pueden ser el mismo');
    }

    public function errorCode(): string
    {
        return 'same_sticker_offer';
    }
}
