<?php

declare(strict_types=1);

namespace Tests\Unit\Trading\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Trading\Domain\Collection\Copies;

final class CopiesTest extends TestCase
{
    public static function invalidStatesProvider(): iterable
    {
        yield 'cantidad negativa' => [-1, 0];
        yield 'reservas negativas' => [1, -1];
        yield 'todas reservadas' => [2, 2];
        yield 'más reservadas que copias' => [1, 2];
        yield 'reservas sin copias' => [0, 1];
    }

    #[Test]
    #[DataProvider('invalidStatesProvider')]
    public function it_rejects_states_that_break_the_invariant(int $quantity, int $reserved): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Copies($quantity, $reserved);
    }

    #[Test]
    public function transitions_return_a_new_instance_and_leave_the_original_untouched(): void
    {
        $original = new Copies(3, 1);

        $reserved = $original->reserve();
        $released = $original->release();
        $handedReserved = $original->handOverReserved();
        $handedSpare = $original->handOverSpare();
        $received = $original->receive();

        $this->assertEquals(new Copies(3, 1), $original);
        $this->assertEquals(new Copies(3, 2), $reserved);
        $this->assertEquals(new Copies(3, 0), $released);
        $this->assertEquals(new Copies(2, 0), $handedReserved);
        $this->assertEquals(new Copies(2, 1), $handedSpare);
        $this->assertEquals(new Copies(4, 1), $received);
    }

    #[Test]
    public function the_last_copy_cannot_be_reserved(): void
    {
        $this->expectException(\UnderflowException::class);

        (new Copies(1, 0))->reserve();
    }

    #[Test]
    public function a_reserved_copy_cannot_be_handed_over_as_spare(): void
    {
        $this->expectException(\UnderflowException::class);

        (new Copies(2, 1))->handOverSpare();
    }

    #[Test]
    public function nothing_can_be_released_without_reservations(): void
    {
        $this->expectException(\LogicException::class);

        (new Copies(2, 0))->release();
    }

    #[Test]
    public function receiving_starts_from_no_copies(): void
    {
        $this->assertEquals(new Copies(1, 0), Copies::none()->receive());
        $this->assertSame(0, Copies::none()->spare());
    }
}
