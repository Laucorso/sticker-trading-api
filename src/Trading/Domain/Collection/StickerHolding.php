<?php

declare(strict_types=1);

namespace Trading\Domain\Collection;

use Trading\Domain\Exception\NoSpareCopy;

/**
 * Las copias que un usuario tiene de un cromo.
 *
 * quantity = copias que posee; reserved = copias comprometidas en ofertas abiertas.
 * Invariante: siempre se conserva al menos una copia sin comprometer
 * (reserved < quantity, o ninguna copia). La BD lo refuerza con un CHECK.
 */
final class StickerHolding
{
    public function __construct(
        private readonly int $userId,
        private readonly int $stickerId,
        private int $quantity,
        private int $reserved,
    ) {
        if ($quantity < 0 || $reserved < 0 || ($quantity > 0 && $reserved >= $quantity) || ($quantity === 0 && $reserved > 0)) {
            throw new \InvalidArgumentException("Holding inválido: {$quantity} copias, {$reserved} reservadas");
        }
    }

    public static function empty(int $userId, int $stickerId): self
    {
        return new self($userId, $stickerId, 0, 0);
    }

    /** Copias que se pueden ofrecer o entregar sin perder la última ni tocar las reservadas. */
    public function spareCopies(): int
    {
        return max(0, $this->quantity - $this->reserved - 1);
    }

    /** Al publicar una oferta: compromete una copia repetida. */
    public function reserveSpare(): void
    {
        $this->ensureSpare();
        $this->reserved++;
    }

    /** Al retirar o caducar una oferta: la copia vuelve a quedar libre. */
    public function releaseReservation(): void
    {
        if ($this->reserved === 0) {
            throw new \LogicException("No hay copias reservadas del cromo {$this->stickerId}");
        }
        $this->reserved--;
    }

    /** El autor entrega la copia que tenía comprometida en la oferta. */
    public function handOverReserved(): void
    {
        $this->releaseReservation();
        $this->quantity--;
    }

    /** Quien acepta entrega una copia repetida que no esté comprometida en otra oferta. */
    public function handOverSpare(): void
    {
        $this->ensureSpare();
        $this->quantity--;
    }

    public function receive(): void
    {
        $this->quantity++;
    }

    private function ensureSpare(): void
    {
        if ($this->spareCopies() < 1) {
            throw new NoSpareCopy($this->userId, $this->stickerId);
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function stickerId(): int
    {
        return $this->stickerId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }
}
