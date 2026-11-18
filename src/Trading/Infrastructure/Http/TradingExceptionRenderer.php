<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Trading\Domain\Exception\CannotAcceptOwnOffer;
use Trading\Domain\Exception\NotOfferAuthor;
use Trading\Domain\Exception\OfferNotFound;
use Trading\Domain\Exception\SameStickerOffer;
use Trading\Domain\Exception\TradingException;

/**
 * El dominio no sabe de HTTP: aquí se decide el status. Por defecto 409
 * (la petición es válida pero choca con el estado actual: oferta cerrada,
 * sin repetidas, límite semanal...).
 */
final class TradingExceptionRenderer
{
    private const STATUS = [
        OfferNotFound::class => 404,
        NotOfferAuthor::class => 403,
        CannotAcceptOwnOffer::class => 422,
        SameStickerOffer::class => 422,
    ];

    public function __invoke(TradingException $e): JsonResponse
    {
        return response()->json(
            ['error' => $e->errorCode(), 'message' => $e->getMessage()],
            self::STATUS[$e::class] ?? 409,
        );
    }
}
