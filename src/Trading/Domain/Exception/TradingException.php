<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

/**
 * Violación de una regla de negocio. errorCode() es estable para que los clientes
 * puedan reaccionar sin parsear mensajes; la capa HTTP decide el status.
 */
abstract class TradingException extends \DomainException
{
    abstract public function errorCode(): string;
}
