<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;
use Trading\Infrastructure\Broadcasting\OfferAcceptedNotification;

final class AcceptOfferTest extends TestCase
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
    }

    #[Test]
    public function accepting_swaps_the_stickers_and_closes_the_offer(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.accepted_by', $this->bruno);

        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($this->ana, $this->messi));
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($this->ana, $this->pedri));
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($this->bruno, $this->pedri));
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($this->bruno, $this->messi));
    }

    #[Test]
    public function the_author_is_notified_on_their_private_channel(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))->assertOk();

        Event::assertDispatched(OfferAcceptedNotification::class, function (OfferAcceptedNotification $n) use ($offerId) {
            return $n->broadcastOn()->name === "private-users.{$this->ana}"
                && $n->broadcastWith()['offer_id'] === $offerId
                && $n->broadcastWith()['accepted_by'] === $this->bruno;
        });
    }

    #[Test]
    public function the_acceptor_must_have_a_spare_copy_of_the_requested_sticker(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 1);
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))
            ->assertConflict()
            ->assertJsonPath('error', 'no_spare_copy');

        $this->assertDatabaseHas('offers', ['id' => $offerId, 'status' => 'open']);
        $this->assertSame(['quantity' => 2, 'reserved' => 1], $this->holding($this->ana, $this->messi));
        Event::assertNotDispatched(OfferAcceptedNotification::class);
    }

    #[Test]
    public function the_acceptor_cannot_hand_over_a_copy_committed_in_their_own_offer(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();
        // Bruno compromete su única repetida de Pedri en una oferta propia.
        $this->postJson('/api/offers', ['offered_sticker_id' => $this->pedri, 'requested_sticker_id' => $this->createSticker()], $this->as($this->bruno))->assertCreated();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))
            ->assertConflict()
            ->assertJsonPath('error', 'no_spare_copy');
    }

    #[Test]
    public function the_author_cannot_accept_their_own_offer(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->ana, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->ana))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'cannot_accept_own_offer');
    }

    #[Test]
    public function an_offer_can_only_be_accepted_once(): void
    {
        $carla = $this->createUser('carla');
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
        $this->giveStickers($carla, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))->assertOk();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($carla))
            ->assertConflict()
            ->assertJsonPath('error', 'offer_not_open');
        $this->assertSame(['quantity' => 2, 'reserved' => 0], $this->holding($carla, $this->pedri));
    }

    #[Test]
    public function an_offer_older_than_48_hours_cannot_be_accepted_even_before_the_sweep(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->giveStickers($this->bruno, $this->pedri, 2);
        $offerId = $this->publishAnaOffer();

        $this->travel(48)->hours();

        $this->postJson("/api/offers/{$offerId}/accept", [], $this->as($this->bruno))
            ->assertConflict()
            ->assertJsonPath('error', 'offer_expired');
    }

    #[Test]
    public function an_unknown_offer_is_not_found(): void
    {
        $this->postJson('/api/offers/999/accept', [], $this->as($this->bruno))
            ->assertNotFound()
            ->assertJsonPath('error', 'offer_not_found');
    }

    private function publishAnaOffer(): int
    {
        return $this->postJson('/api/offers', [
            'offered_sticker_id' => $this->messi,
            'requested_sticker_id' => $this->pedri,
        ], $this->as($this->ana))->assertCreated()->json('data.id');
    }
}
