<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class AccountDeleted extends TradingException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct("La cuenta del usuario {$userId} ha sido eliminada");
    }

    public function errorCode(): string
    {
        return 'account_deleted';
    }
}
