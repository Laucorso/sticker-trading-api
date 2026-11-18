<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;
use Trading\Application\AcceptOffer;
use Trading\Application\PublishOffer;
use Trading\Domain\Exception\AccountDeleted;
use Trading\Infrastructure\Broadcasting\OfferAcceptedNotification;
use Trading\Infrastructure\Broadcasting\OfferExpiredNotification;

final class DeleteAccountTest extends TestCase
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
        Event::fake([OfferAcceptedNotification::class, OfferExpiredNotification::class]);

        $this->ana = $this->createUser('ana');
        $this->bruno = $this->createUser('bruno');
        $this->messi = $this->createSticker('Messi');
        $this->pedri = $this->createSticker('Pedri');
        $this->giveStickers($this->ana, $this->messi, 3);
        $this->giveStickers($this->bruno, $this->pedri, 2);
    }

    #[Test]
    public function deleting_the_account_withdraws_open_offers_and_releases_the_copies(): void
    {
        $first = $this->publishAnaOffer();
        $second = $this->publishAnaOffer();

        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $this->assertDatabaseHas('offers', ['id' => $first, 'status' => 'withdrawn']);
        $this->assertDatabaseHas('offers', ['id' => $second, 'status' => 'withdrawn']);
        $this->assertSame(['quantity' => 3, 'reserved' => 0], $this->holding($this->ana, $this->messi));
        $this->postJson("/api/offers/{$first}/accept", [], $this->as($this->bruno))
            ->assertConflict()
            ->assertJsonPath('error', 'offer_not_open');
    }

    #[Test]
    public function the_account_is_anonymised_but_the_trade_history_is_kept(): void
    {
        $traded = $this->publishAnaOffer();
        $this->postJson("/api/offers/{$traded}/accept", [], $this->as($this->bruno))->assertOk();

        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $user = DB::table('users')->find($this->ana);
        $this->assertNotNull($user->deleted_at);
        $this->assertSame('Usuario eliminado', $user->name);
        $this->assertSame("deleted-{$this->ana}@invalid", $user->email);
        // La otra parte conserva su intercambio con la cuenta borrada.
        $this->assertDatabaseHas('offers', ['id' => $traded, 'status' => 'accepted', 'author_id' => $this->ana, 'accepted_by' => $this->bruno]);
    }

    #[Test]
    public function an_offer_past_its_ttl_not_yet_swept_is_expired_and_its_copy_released(): void
    {
        $offerId = $this->publishAnaOffer();
        $this->travel(49)->hours();

        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $this->assertDatabaseHas('offers', ['id' => $offerId, 'status' => 'expired']);
        $this->assertSame(['quantity' => 3, 'reserved' => 0], $this->holding($this->ana, $this->messi));
    }

    #[Test]
    public function a_deleted_account_can_no_longer_authenticate(): void
    {
        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $this->getJson('/api/offers', $this->as($this->ana))->assertUnauthorized();
        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertUnauthorized();
    }

    #[Test]
    public function other_users_offers_are_untouched(): void
    {
        $this->giveStickers($this->bruno, $this->messi, 2);
        $brunoOffer = $this->postJson('/api/offers', [
            'offered_sticker_id' => $this->messi,
            'requested_sticker_id' => $this->pedri,
        ], $this->as($this->bruno))->assertCreated()->json('data.id');

        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $this->assertDatabaseHas('offers', ['id' => $brunoOffer, 'status' => 'open']);
        $this->assertSame(['quantity' => 2, 'reserved' => 1], $this->holding($this->bruno, $this->messi));
    }

    /*
     * Por HTTP una cuenta borrada ya no se autentica. Estos dos casos cubren la
     * carrera: la petición se autenticó justo antes de que se confirmara el borrado.
     */

    #[Test]
    public function a_deleted_account_cannot_publish_even_if_the_request_was_already_authenticated(): void
    {
        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        $this->expectException(AccountDeleted::class);

        app(PublishOffer::class)($this->ana, $this->messi, $this->pedri);
    }

    #[Test]
    public function a_deleted_account_cannot_accept_even_if_the_request_was_already_authenticated(): void
    {
        $this->giveStickers($this->bruno, $this->messi, 2);
        $brunoOffer = app(PublishOffer::class)($this->bruno, $this->messi, $this->pedri)->id();
        $this->giveStickers($this->ana, $this->pedri, 2);
        $this->deleteJson('/api/me', [], $this->as($this->ana))->assertNoContent();

        try {
            app(AcceptOffer::class)($brunoOffer, $this->ana);
            $this->fail('Debería haber lanzado AccountDeleted');
        } catch (AccountDeleted $e) {
            $this->assertSame('account_deleted', $e->errorCode());
        }

        $this->assertDatabaseHas('offers', ['id' => $brunoOffer, 'status' => 'open']);
    }

    private function publishAnaOffer(): int
    {
        return $this->postJson('/api/offers', [
            'offered_sticker_id' => $this->messi,
            'requested_sticker_id' => $this->pedri,
        ], $this->as($this->ana))->assertCreated()->json('data.id');
    }
}
