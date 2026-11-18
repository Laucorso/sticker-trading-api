<?php

declare(strict_types=1);

namespace Trading\Application\Query;

/**
 * Lado de lectura: consultas planas pensadas para la API, sin pasar por las
 * entidades (no hay reglas que aplicar al leer).
 */
interface TradingReadModel
{
    /**
     * Ofertas abiertas y no caducadas, más recientes primero.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function openOffers(?int $offeredStickerId, ?int $requestedStickerId, ?int $authorId, int $perPage, ?string $cursor): array;

    /** @return list<array{sticker_id: int, name: string, quantity: int, reserved: int, tradeable: int}> */
    public function collectionOf(int $userId): array;
}
