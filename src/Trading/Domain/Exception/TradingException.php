<?php

declare(strict_types=1);

namespace Trading\Domain\Exception;

/**
 * Error esperado que se devuelve al cliente: casi siempre la violación de una regla
 * de negocio, y algún caso de concurrencia con el mismo contrato (OfferBusy).
 * errorCode() es estable para que los clientes puedan reaccionar sin parsear
 * mensajes; la capa HTTP decide el status.
 */
abstract class TradingException extends \DomainException
{
    abstract public function errorCode(): string;
}
