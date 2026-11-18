<?php

declare(strict_types=1);

namespace Trading\Domain\Collection;

interface CollectionRepository
{
    /** Devuelve un holding vacío si el usuario no tiene el cromo. */
    public function holdingOf(int $userId, int $stickerId): StickerHolding;

    public function save(StickerHolding $holding): void;
}
