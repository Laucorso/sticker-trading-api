<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

final class WeeklyTradeLimitReached extends TradingException
{
    public function __construct(int $max)
    {
        parent::__construct("Esta pareja de usuarios ya ha hecho {$max} intercambios en los últimos 7 días");
    }

    public function errorCode(): string
    {
        return 'weekly_trade_limit_reached';
    }
}
