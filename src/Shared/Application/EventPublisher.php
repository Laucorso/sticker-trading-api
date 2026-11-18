<?php

declare(strict_types=1);

namespace Shared\Application;

interface EventPublisher
{
    /** Se llama siempre después del commit: nunca se notifica algo que pudo hacer rollback. */
    public function publish(object ...$events): void;
}
