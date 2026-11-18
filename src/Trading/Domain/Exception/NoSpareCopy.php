<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class NoSpareCopy extends TradingException
{
    public function __construct(public readonly int $userId, public readonly int $stickerId)
    {
        parent::__construct("El usuario {$userId} no tiene copias repetidas libres del cromo {$stickerId}");
    }

    public function errorCode(): string
    {
        return 'no_spare_copy';
    }
}
