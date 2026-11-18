<?php

declare(strict_types=1);

namespace Trading\Domain\Collection;

/**
 * Copias de un cromo: cuántas se tienen y cuántas están comprometidas en ofertas.
 *
 * Inmutable: cada operación devuelve una instancia nueva.
 * Regla: siempre queda al menos una copia sin comprometer (la BD lo refuerza con un CHECK).
 *
 * Si no hay repetidas libres lanza \UnderflowException; StickerHolding la traduce
 * a NoSpareCopy con el usuario y el cromo.
 */
final readonly class Copies
{
    public function __construct(
        public int $quantity,
        public int $reserved,
    ) {
        if (! self::isValid($quantity, $reserved)) {
            throw new \InvalidArgumentException("Copias inválidas: {$quantity} copias, {$reserved} reservadas");
        }
    }

    public static function none(): self
    {
        return new self(quantity: 0, reserved: 0);
    }

    /** Copias que se pueden ofrecer o entregar: ni la última ni las reservadas. */
    public function spare(): int
    {
        return max(0, $this->quantity - $this->reserved - 1);
    }

    public function hasSpare(): bool
    {
        return $this->spare() > 0;
    }

    /** Al publicar una oferta. */
    public function reserve(): self
    {
        $this->ensureSpare();

        return $this->with(reserved: $this->reserved + 1);
    }

    /** Al retirar o caducar una oferta. */
    public function release(): self
    {
        if ($this->reserved === 0) {
            throw new \LogicException('No hay copias reservadas');
        }

        return $this->with(reserved: $this->reserved - 1);
    }

    /** El autor entrega la copia que tenía comprometida en la oferta. */
    public function handOverReserved(): self
    {
        return $this->release()->withOneLess();
    }

    /** Quien acepta entrega una repetida que no esté comprometida. */
    public function handOverSpare(): self
    {
        $this->ensureSpare();

        return $this->withOneLess();
    }

    public function receive(): self
    {
        return $this->with(quantity: $this->quantity + 1);
    }

    private function withOneLess(): self
    {
        return $this->with(quantity: $this->quantity - 1);
    }

    private function with(?int $quantity = null, ?int $reserved = null): self
    {
        return new self($quantity ?? $this->quantity, $reserved ?? $this->reserved);
    }

    private function ensureSpare(): void
    {
        if (! $this->hasSpare()) {
            throw new \UnderflowException('No quedan copias repetidas libres');
        }
    }

    /** Sin copias no puede haber reservas; con copias, al menos una queda libre. */
    private static function isValid(int $quantity, int $reserved): bool
    {
        if ($quantity < 0 || $reserved < 0) {
            return false;
        }

        return $quantity === 0 ? $reserved === 0 : $reserved < $quantity;
    }
}