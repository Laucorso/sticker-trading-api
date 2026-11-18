<?php

declare(strict_types=1);

namespace Trading\Domain\Collection;

use Trading\Domain\Exception\NoSpareCopy;

/**
 * Las copias que un usuario tiene de un cromo.
 *
 * Entidad identificada por (userId, stickerId). La aritmética y la invariante de las
 * copias viven en el VO inmutable Copies; la entidad solo lo sustituye en cada
 * transición y aporta el contexto (de quién son) a los errores de dominio.
 */
final class StickerHolding
{
    private Copies $copies;

    public function __construct(
        private readonly int $userId,
        private readonly int $stickerId,
        int $quantity,
        int $reserved,
    ) {
        $this->copies = new Copies($quantity, $reserved);
    }

    public static function empty(int $userId, int $stickerId): self
    {
        return new self($userId, $stickerId, 0, 0);
    }

    /** Copias que se pueden ofrecer o entregar sin perder la última ni tocar las reservadas. */
    public function spareCopies(): int
    {
        return $this->copies->spare();
    }

    /** Al publicar una oferta: compromete una copia repetida. */
    public function reserveSpare(): void
    {
        $this->copies = $this->spareOrFail(fn () => $this->copies->reserve());
    }

    /** Al retirar o caducar una oferta: la copia vuelve a quedar libre. */
    public function releaseReservation(): void
    {
        $this->copies = $this->copies->release();
    }

    /** El autor entrega la copia que tenía comprometida en la oferta. */
    public function handOverReserved(): void
    {
        $this->copies = $this->copies->handOverReserved();
    }

    /** Quien acepta entrega una copia repetida que no esté comprometida en otra oferta. */
    public function handOverSpare(): void
    {
        $this->copies = $this->spareOrFail(fn () => $this->copies->handOverSpare());
    }

    public function receive(): void
    {
        $this->copies = $this->copies->receive();
    }

    /** @param callable(): Copies $transition */
    private function spareOrFail(callable $transition): Copies
    {
        try {
            return $transition();
        } catch (\UnderflowException) {
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

    public function copies(): Copies
    {
        return $this->copies;
    }

    public function quantity(): int
    {
        return $this->copies->quantity;
    }

    public function reserved(): int
    {
        return $this->copies->reserved;
    }
}
