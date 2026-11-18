<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Shared\Domain\Clock;

/** Usa Carbon para que los tests puedan mover el tiempo con $this->travel(). */
final class CarbonClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now('UTC')->toDateTimeImmutable();
    }
}
