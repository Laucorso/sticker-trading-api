<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;
use Trading\Infrastructure\Broadcasting\OfferAcceptedNotification;

final class WeeklyTradeLimitTest extends TestCase
{
    use RefreshDatabase;
    use TradingFixtures;

    private int $ana;

    private int $bruno;

    private int $messi;

    private int $pedri;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OfferAcceptedNotification::class]);

        $this->ana = $this->createUser('ana');
        $this->bruno = $this->createUser('bruno');
        $this->messi = $this->createSticker('Messi');
        $this->pedri = $this->createSticker('Pedri');
        // Copias de sobra para que el único límite que entre en juego sea el semanal.
        $this->giveStickers($this->ana, $this->messi, 50);
        $this->giveStickers($this->ana, $this->pedri, 50);
        $this->giveStickers($this->bruno, $this->messi, 50);
        $this->giveStickers($this->bruno, $this->pedri, 50);
    }

    #[Test]
    public function the_sixth_trade_in_a_week_between_the_same_pair_is_rejected_whoever_authored_it(): void
    {
        // Se cuentan los dos sentidos: 3 ofertas de Ana y 2 de Bruno.
        $this->trade($this->ana, $this->bruno)->assertOk();
        $this->trade($this->bruno, $this->ana)->assertOk();
        $this->trade($this->ana, $this->bruno)->assertOk();
        $this->trade($this->bruno, $this->ana)->assertOk();
        $this->trade($this->ana, $this->bruno)->assertOk();

        $this->trade($this->bruno, $this->ana)
            ->assertConflict()
            ->assertJsonPath('error', 'weekly_trade_limit_reached');
    }

    #[Test]
    public function the_limit_is_per_pair(): void
    {
        $carla = $this->createUser('carla');
        $this->giveStickers($carla, $this->pedri, 50);

        for ($i = 0; $i < 5; $i++) {
            $this->trade($this->ana, $this->bruno)->assertOk();
        }

        $this->trade($this->ana, $carla)->assertOk();
    }

    #[Test]
    public function the_window_is_a_rolling_seven_days(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->trade($this->ana, $this->bruno)->assertOk();
        }

        $this->travel(6)->days();
        $this->trade($this->ana, $this->bruno)->assertConflict();

        $this->travel(1)->days();
        $this->trade($this->ana, $this->bruno)->assertOk();
    }

    private function trade(int $author, int $acceptor): TestResponse
    {
        $offerId = $this->postJson('/api/offers', [
            'offered_sticker_id' => $this->messi,
            'requested_sticker_id' => $this->pedri,
        ], $this->as($author))->assertCreated()->json('data.id');

        return $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($acceptor));
    }
}
