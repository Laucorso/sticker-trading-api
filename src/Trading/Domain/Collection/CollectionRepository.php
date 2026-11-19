<?php

declare(strict_types=1);

namespace Trading\Domain\Collection;

interface CollectionRepository
{
    /**
     * Bloquea las copias del usuario de ese cromo hasta el final de la transacción.
     * Devuelve un holding vacío si el usuario no tiene el cromo.
     */
    public function holdingOf(int $userId, int $stickerId): StickerHolding;

    public function save(StickerHolding $holding): void;
}
