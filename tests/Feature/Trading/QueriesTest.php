<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TradingFixtures;
use Tests\TestCase;

final class QueriesTest extends TestCase
{
    use RefreshDatabase;
    use TradingFixtures;

    #[Test]
    public function it_lists_open_offers_newest_first_with_filters_and_cursor(): void
    {
        $ana = $this->createUser('ana');
        [$a, $b, $c] = [$this->createSticker('A'), $this->createSticker('B'), $this->createSticker('C')];
        $this->giveStickers($ana, $a, 5);

        $first = $this->publish($ana, $a, $b);
        $second = $this->publish($ana, $a, $c);
        $withdrawn = $this->publish($ana, $a, $b);
        $this->postJson("/api/offers/{$withdrawn}/withdraw", [], $this->as($ana))->assertOk();

        $page = $this->getJson('/api/offers?per_page=1', $this->as($ana))
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.offered_sticker.name', 'A')
            ->assertJsonPath('data.0.author.name', 'ana');

        $this->getJson('/api/offers?per_page=1&cursor='.$page->json('next_cursor'), $this->as($ana))
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('next_cursor', null);

        $this->getJson("/api/offers?requested_sticker_id={$c}", $this->as($ana))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);
    }

    #[Test]
    public function the_collection_shows_how_many_copies_can_be_traded(): void
    {
        $ana = $this->createUser('ana');
        [$a, $b] = [$this->createSticker('A'), $this->createSticker('B')];
        $this->giveStickers($ana, $a, 3);
        $this->giveStickers($ana, $b, 1);
        $this->publish($ana, $a, $b);

        $this->getJson("/api/users/{$ana}/collection", $this->as($ana))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['sticker_id' => $a, 'name' => 'A', 'quantity' => 3, 'reserved' => 1, 'tradeable' => 1],
                ['sticker_id' => $b, 'name' => 'B', 'quantity' => 1, 'reserved' => 0, 'tradeable' => 0],
            ]]);
    }

    #[Test]
    public function the_collection_of_an_unknown_user_is_not_found(): void
    {
        $this->getJson('/api/users/999/collection', $this->as($this->createUser()))->assertNotFound();
    }

    private function publish(int $author, int $offered, int $requested): int
    {
        return $this->postJson('/api/offers', ['offered_sticker_id' => $offered, 'requested_sticker_id' => $requested], $this->as($author))
            ->assertCreated()
            ->json('data.id');
    }
}
