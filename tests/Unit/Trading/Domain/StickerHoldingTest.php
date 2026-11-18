<?php

declare(strict_types=1);

namespace Tests\Unit\Trading\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Trading\Domain\Collection\StickerHolding;
use Trading\Domain\Exception\NoSpareCopy;

final class StickerHoldingTest extends TestCase
{
    public static function spareCopiesProvider(): iterable
    {
        yield 'sin copias' => [0, 0, 0];
        yield 'una sola copia' => [1, 0, 0];
        yield 'dos copias' => [2, 0, 1];
        yield 'tres copias, una reservada' => [3, 1, 1];
        yield 'tres copias, dos reservadas' => [3, 2, 0];
    }

    #[Test]
    #[DataProvider('spareCopiesProvider')]
    public function spare_copies_never_include_the_last_one_nor_reserved_ones(int $quantity, int $reserved, int $expected): void
    {
        $this->assertSame($expected, (new StickerHolding(1, 1, $quantity, $reserved))->spareCopies());
    }

    #[Test]
    public function the_last_copy_cannot_be_reserved(): void
    {
        $this->expectException(NoSpareCopy::class);

        (new StickerHolding(1, 1, 1, 0))->reserveSpare();
    }

    #[Test]
    public function a_reserved_copy_cannot_be_used_in_another_offer(): void
    {
        $holding = new StickerHolding(1, 1, 2, 0);
        $holding->reserveSpare();

        $this->expectException(NoSpareCopy::class);

        $holding->reserveSpare();
    }

    #[Test]
    public function a_reserved_copy_cannot_be_handed_over_as_spare(): void
    {
        $holding = new StickerHolding(1, 1, 2, 1);

        $this->expectException(NoSpareCopy::class);

        $holding->handOverSpare();
    }

    #[Test]
    public function handing_over_the_reserved_copy_keeps_the_last_one(): void
    {
        $holding = new StickerHolding(1, 1, 2, 1);

        $holding->handOverReserved();

        $this->assertSame(1, $holding->quantity());
        $this->assertSame(0, $holding->reserved());
    }

    #[Test]
    public function releasing_a_reservation_frees_the_copy_again(): void
    {
        $holding = new StickerHolding(1, 1, 2, 1);

        $holding->releaseReservation();

        $this->assertSame(1, $holding->spareCopies());
    }

    #[Test]
    public function it_rejects_states_that_break_the_invariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StickerHolding(1, 1, 2, 2);
    }
}
