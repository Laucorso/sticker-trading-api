<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;

final class PublishOfferTest extends TestCase
{
    use RefreshDatabase;
    use TradingFixtures;

    private int $ana;

    private int $messi;

    private int $pedri;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->createUser('ana');
        $this->messi = $this->createSticker('Messi');
        $this->pedri = $this->createSticker('Pedri');
    }

    #[Test]
    public function publishing_an_offer_reserves_one_spare_copy(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);

        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.author_id', $this->ana);

        $this->assertSame(['quantity' => 2, 'reserved' => 1], $this->holding($this->ana, $this->messi));
    }

    #[Test]
    public function the_last_copy_cannot_be_offered(): void
    {
        $this->giveStickers($this->ana, $this->messi, 1);

        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))
            ->assertConflict()
            ->assertJsonPath('error', 'no_spare_copy');

        $this->assertDatabaseCount('offers', 0);
    }

    #[Test]
    public function a_sticker_you_do_not_own_cannot_be_offered(): void
    {
        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))
            ->assertConflict()
            ->assertJsonPath('error', 'no_spare_copy');
    }

    #[Test]
    public function a_copy_committed_in_an_open_offer_cannot_be_used_in_another(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);
        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))->assertCreated();

        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))
            ->assertConflict()
            ->assertJsonPath('error', 'no_spare_copy');
    }

    #[Test]
    public function each_spare_copy_can_back_its_own_offer(): void
    {
        $this->giveStickers($this->ana, $this->messi, 3);

        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))->assertCreated();
        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))->assertCreated();
        $this->postJson('/api/offers', $this->payload(), $this->as($this->ana))->assertConflict();

        $this->assertSame(['quantity' => 3, 'reserved' => 2], $this->holding($this->ana, $this->messi));
    }

    #[Test]
    public function offering_a_sticker_for_itself_is_rejected(): void
    {
        $this->giveStickers($this->ana, $this->messi, 2);

        $this->postJson('/api/offers', ['offered_sticker_id' => $this->messi, 'requested_sticker_id' => $this->messi], $this->as($this->ana))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'same_sticker_offer');

        $this->assertSame(0, $this->holding($this->ana, $this->messi)['reserved']);
    }

    #[Test]
    public function unknown_stickers_are_a_validation_error(): void
    {
        $this->postJson('/api/offers', ['offered_sticker_id' => 999, 'requested_sticker_id' => $this->pedri], $this->as($this->ana))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offered_sticker_id');
    }

    #[Test]
    public function it_requires_a_user(): void
    {
        $this->postJson('/api/offers', $this->payload())->assertUnauthorized();
        $this->postJson('/api/offers', $this->payload(), ['X-User-Id' => '999'])->assertUnauthorized();
    }

    /** @return array<string, int> */
    private function payload(): array
    {
        return ['offered_sticker_id' => $this->messi, 'requested_sticker_id' => $this->pedri];
    }
}
