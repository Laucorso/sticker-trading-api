<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;
use Trading\Infrastructure\Broadcasting\OfferExpiredNotification;

final class WithdrawAndExpireOfferTest extends TestCase
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
        Event::fake([OfferExpiredNotification::class]);

        $this->ana = $this->createUser('ana');
        $this->bruno = $this->createUser('bruno');
        $this->messi = $this->createSticker('Messi');
        $this->pedri = $this->createSticker('Pedri');
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
    }

    #[Test]
    public function the_author_can_withdraw_an_open_offer_and_the_copy_is_released(): void
    {
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/withdraw", [], $this->as($this->ana))
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame(['quantity' => 2, 'reserved' => 0], $this->holding($this->ana, $this->messi));
        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))
            ->assertConflict()
            ->assertJsonPath('error', 'offer_not_open');
    }

    #[Test]
    public function only_the_author_can_withdraw(): void
    {
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/withdraw", [], $this->as($this->bruno))
            ->assertForbidden()
            ->assertJsonPath('error', 'not_offer_author');
    }

    #[Test]
    public function an_accepted_offer_cannot_be_withdrawn(): void
    {
        $offerId = $this->publishAnaOffer();
        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))->assertOk();

        $this->postJson("/api/offers/{$offerId}/withdraw", [], $this->as($this->ana))
            ->assertConflict()
            ->assertJsonPath('error', 'offer_not_open');
    }

    #[Test]
    public function the_sweep_expires_offers_older_than_48_hours_and_releases_the_copy(): void
    {
        $old = $this->publishAnaOffer();
        $this->travel(47)->hours();
        $this->giveStickers($this->ana, $recent = $this->createSticker(), 2);
        $fresh = $this->postJson('/api/offers', ['offered_sticker_id' => $recent, 'requested_sticker_id' => $this->pedri], $this->as($this->ana))->json('data.id');

        $this->travel(1)->hours();
        $this->artisan('offers:expire')->assertSuccessful()->expectsOutputToContain('Ofertas caducadas: 1');

        $this->assertDatabaseHas('offers', ['id' => $old, 'status' => 'expired']);
        $this->assertDatabaseHas('offers', ['id' => $fresh, 'status' => 'open']);
        $this->assertSame(['quantity' => 2, 'reserved' => 0], $this->holding($this->ana, $this->messi));
        Event::assertDispatched(OfferExpiredNotification::class, fn ($n) => $n->expired->offerId === $old);
    }

    #[Test]
    public function the_sweep_is_idempotent(): void
    {
        $this->publishAnaOffer();
        $this->travel(48)->hours();

        $this->artisan('offers:expire')->expectsOutputToContain('Ofertas caducadas: 1');
        $this->artisan('offers:expire')->expectsOutputToContain('Ofertas caducadas: 0');

        $this->assertSame(['quantity' => 2, 'reserved' => 0], $this->holding($this->ana, $this->messi));
    }

    #[Test]
    public function an_expired_offer_is_hidden_from_the_listing_before_the_sweep_runs(): void
    {
        $this->publishAnaOffer();
        $this->getJson('/api/offers', $this->as($this->bruno))->assertJsonCount(1, 'data');

        $this->travel(48)->hours();

        $this->getJson('/api/offers', $this->as($this->bruno))->assertJsonCount(0, 'data');
    }

    private function publishAnaOffer(): int
    {
        return $this->postJson('/api/offers', [
            'offered_sticker_id' => $this->messi,
            'requested_sticker_id' => $this->pedri,
        ], $this->as($this->ana))->assertCreated()->json('data.id');
    }
}
