<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Trading\Application\AcceptOffer;
use Trading\Application\DeleteAccount;
use Trading\Application\PublishOffer;
use Trading\Application\WithdrawOffer;

final class ConcurrentTradingTest extends ConcurrencyTestCase
{
    private int $messi;

    private int $pedri;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'null']);

        $this->messi = $this->createSticker('Messi');
        $this->pedri = $this->createSticker('Pedri');
    }

    #[Test]
    public function when_many_users_accept_the_same_offer_at_once_only_one_succeeds(): void
    {
        $ana = $this->createUser('ana');
        $this->giveStickers($ana, $this->messi, 2);
        $offer = app(PublishOffer::class)($ana, $this->messi, $this->pedri);

        $acceptors = [];
        for ($i = 0; $i < 20; $i++) {
            $acceptors[] = $user = $this->createUser("acceptor{$i}");
            $this->giveStickers($user, $this->pedri, 2);
        }

        $results = $this->runConcurrently(array_map(
            fn (int $user) => fn () => app(AcceptOffer::class)($offer->id(), $user),
            $acceptors,
        ));

        // Gana uno. El resto no hace cola: quien llega mientras el ganador la procesa
        // recibe offer_busy al momento, y quien llega después del commit, offer_not_open.
        $this->assertCount(1, array_keys($results, 'ok', true), 'Resultados: '.json_encode($results));
        $losers = array_values(array_diff($results, ['ok']));
        $this->assertSame([], array_values(array_diff($losers, ['offer_busy', 'offer_not_open'])), 'Resultados: '.json_encode($results));

        $winner = $acceptors[array_search('ok', $results, true)];
        $this->assertDatabaseHas('offers', ['id' => $offer->id(), 'status' => 'accepted', 'accepted_by' => $winner]);
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($ana, $this->messi));
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($ana, $this->pedri));
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($winner, $this->messi));
        // Ningún cromo se crea ni se destruye.
        $this->assertSame(2, $this->totalCopies($this->messi));
        $this->assertSame(40, $this->totalCopies($this->pedri));
    }

    #[Test]
    public function accepting_an_offer_that_is_being_processed_fails_at_once_instead_of_queueing(): void
    {
        $ana = $this->createUser('ana');
        $bruno = $this->createUser('bruno');
        $this->giveStickers($ana, $this->messi, 2);
        $this->giveStickers($bruno, $this->pedri, 2);
        $offerId = app(PublishOffer::class)($ana, $this->messi, $this->pedri)->id();

        $results = $this->runConcurrently([
            // Otra transacción retiene la oferta 1,5 s.
            fn () => DB::transaction(function () use ($offerId): void {
                DB::table('offers')->where('id', $offerId)->lockForUpdate()->first();
                usleep(1_500_000);
            }),
            function () use ($offerId, $bruno): void {
                usleep(300_000);
                $start = microtime(true);
                try {
                    app(AcceptOffer::class)($offerId, $bruno);
                } finally {
                    // Si hubiera esperado al lock (o reintentado), tardaría más de un segundo.
                    if (microtime(true) - $start > 0.5) {
                        throw new \RuntimeException('Ha hecho cola');
                    }
                }
            },
        ]);

        $this->assertSame(['ok', 'offer_busy'], $results);
        $this->assertDatabaseHas('offers', ['id' => $offerId, 'status' => 'open']);
        $this->assertSame(['quantity' => 2, 'reserved' => 1], $this->holding($ana, $this->messi));
    }

    #[Test]
    public function a_user_accepting_many_offers_at_once_cannot_spend_the_same_spare_twice(): void
    {
        $bruno = $this->createUser('bruno');
        $this->giveStickers($bruno, $this->pedri, 2); // una sola repetida

        $offers = [];
        for ($i = 0; $i < 10; $i++) {
            $author = $this->createUser("author{$i}");
            $this->giveStickers($author, $this->messi, 2);
            $offers[] = app(PublishOffer::class)($author, $this->messi, $this->pedri)->id();
        }

        $results = $this->runConcurrently(array_map(
            fn (int $offerId) => fn () => app(AcceptOffer::class)($offerId, $bruno),
            $offers,
        ));

        $this->assertOutcomes(['ok' => 1, 'no_spare_copy' => 9], $results);
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($bruno, $this->pedri));
        $this->assertSame(1, DB::table('offers')->where('status', 'accepted')->count());
        $this->assertSame(9, DB::table('offers')->where('status', 'open')->count());
    }

    #[Test]
    public function the_weekly_limit_holds_when_the_same_pair_trades_concurrently(): void
    {
        $ana = $this->createUser('ana');
        $bruno = $this->createUser('bruno');
        $this->giveStickers($ana, $this->messi, 20);
        $this->giveStickers($bruno, $this->pedri, 20);

        $offers = [];
        for ($i = 0; $i < 10; $i++) {
            $offers[] = app(PublishOffer::class)($ana, $this->messi, $this->pedri)->id();
        }

        $results = $this->runConcurrently(array_map(
            fn (int $offerId) => fn () => app(AcceptOffer::class)($offerId, $bruno),
            $offers,
        ));

        $this->assertOutcomes(['ok' => 5, 'weekly_trade_limit_reached' => 5], $results);
        $this->assertSame(5, DB::table('offers')->where('status', 'accepted')->count());
    }

    #[Test]
    public function the_last_spare_copy_cannot_be_committed_in_two_offers_at_once(): void
    {
        $ana = $this->createUser('ana');
        $this->giveStickers($ana, $this->messi, 2);

        $results = $this->runConcurrently(array_fill(
            0,
            10,
            fn () => app(PublishOffer::class)($ana, $this->messi, $this->pedri),
        ));

        $this->assertOutcomes(['ok' => 1, 'no_spare_copy' => 9], $results);
        $this->assertSame(['quantity' => 2, 'reserved' => 1], $this->holding($ana, $this->messi));
        $this->assertSame(1, DB::table('offers')->count());
    }

    #[Test]
    public function accepting_and_withdrawing_the_same_offer_at_once_leaves_a_consistent_state(): void
    {
        $ana = $this->createUser('ana');
        $bruno = $this->createUser('bruno');
        $this->giveStickers($ana, $this->messi, 11);
        $this->giveStickers($bruno, $this->pedri, 11);

        $operations = [];
        for ($i = 0; $i < 5; $i++) {
            $offerId = app(PublishOffer::class)($ana, $this->messi, $this->pedri)->id();
            $operations[] = fn () => app(AcceptOffer::class)($offerId, $bruno);
            $operations[] = fn () => app(WithdrawOffer::class)($offerId, $ana);
        }

        $results = $this->runConcurrently($operations);

        // En cada pareja (aceptar, retirar) gana exactamente una de las dos. Retirar espera
        // al lock; aceptar no (offer_busy si la retirada ya tenía la oferta bloqueada).
        foreach (array_chunk($results, 2) as [$accept, $withdraw]) {
            $pair = json_encode([$accept, $withdraw]);
            $this->assertCount(1, array_keys([$accept, $withdraw], 'ok', true), "Pareja: {$pair}");
            $this->assertContains($accept, ['ok', 'offer_not_open', 'offer_busy'], "Pareja: {$pair}");
            $this->assertContains($withdraw, ['ok', 'offer_not_open'], "Pareja: {$pair}");
        }

        $accepted = DB::table('offers')->where('status', 'accepted')->count();
        $this->assertSame(5, $accepted + DB::table('offers')->where('status', 'withdrawn')->count());
        // Todas las reservas se han resuelto: ni copias bloqueadas para siempre ni perdidas.
        $this->assertSame(['quantity' => 11 - $accepted, 'reserved' => 0], $this->holding($ana, $this->messi));
        $this->assertSame(11, $this->totalCopies($this->messi));
        $this->assertSame(11, $this->totalCopies($this->pedri));
    }

    #[Test]
    public function many_users_accepting_different_offers_of_the_same_author_at_once_all_succeed_without_losing_copies(): void
    {
        // Ana no tiene ningún Pedri: las 30 aceptaciones crean y suman la misma fila a la vez.
        $ana = $this->createUser('ana');
        $this->giveStickers($ana, $this->messi, 31);

        $operations = [];
        for ($i = 0; $i < 30; $i++) {
            $offerId = app(PublishOffer::class)($ana, $this->messi, $this->pedri)->id();
            $acceptor = $this->createUser("acceptor{$i}");
            $this->giveStickers($acceptor, $this->pedri, 2);
            $operations[] = fn () => app(AcceptOffer::class)($offerId, $acceptor);
        }

        $results = $this->runConcurrently($operations);

        $this->assertOutcomes(['ok' => 30], $results);
        $this->assertSame(['quantity' => 1, 'reserved' => 0], $this->holding($ana, $this->messi));
        $this->assertSame(['quantity' => 30, 'reserved' => 0], $this->holding($ana, $this->pedri));
        $this->assertSame(31, $this->totalCopies($this->messi));
        $this->assertSame(60, $this->totalCopies($this->pedri));
    }

    #[Test]
    public function the_weekly_limit_holds_when_the_pair_trades_in_both_directions_at_once(): void
    {
        $ana = $this->createUser('ana');
        $bruno = $this->createUser('bruno');
        $this->giveStickers($ana, $this->messi, 20);
        $this->giveStickers($bruno, $this->pedri, 20);

        $operations = [];
        for ($i = 0; $i < 5; $i++) {
            $anaOffer = app(PublishOffer::class)($ana, $this->messi, $this->pedri)->id();
            $brunoOffer = app(PublishOffer::class)($bruno, $this->pedri, $this->messi)->id();
            $operations[] = fn () => app(AcceptOffer::class)($anaOffer, $bruno);
            $operations[] = fn () => app(AcceptOffer::class)($brunoOffer, $ana);
        }

        $results = $this->runConcurrently($operations);

        $this->assertOutcomes(['ok' => 5, 'weekly_trade_limit_reached' => 5], $results);
        $this->assertSame(5, DB::table('offers')->where('status', 'accepted')->count());
    }

    #[Test]
    public function accepting_an_offer_while_its_author_deletes_the_account_leaves_a_consistent_state(): void
    {
        $bruno = $this->createUser('bruno');
        $this->giveStickers($bruno, $this->pedri, 11);

        $authors = $offers = $operations = [];
        for ($i = 0; $i < 5; $i++) {
            $authors[$i] = $author = $this->createUser("author{$i}");
            $this->giveStickers($author, $this->messi, 2);
            $offers[$i] = $offerId = app(PublishOffer::class)($author, $this->messi, $this->pedri)->id();
            $operations[] = fn () => app(AcceptOffer::class)($offerId, $bruno);
            $operations[] = fn () => app(DeleteAccount::class)($author);
        }

        $results = $this->runConcurrently($operations);

        foreach (array_chunk($results, 2) as $i => [$accept, $delete]) {
            // El borrado siempre se completa; la aceptación gana o encuentra la oferta retirada.
            $this->assertSame('ok', $delete, "Borrado {$i}");
            $this->assertContains($accept, ['ok', 'offer_not_open', 'offer_busy'], "Aceptación {$i}");

            $status = DB::table('offers')->where('id', $offers[$i])->value('status');
            $this->assertSame($accept === 'ok' ? 'accepted' : 'withdrawn', $status);
            // O se entregó la copia reservada, o se liberó: nunca queda reservada.
            $this->assertSame(
                ['quantity' => $accept === 'ok' ? 1 : 2, 'reserved' => 0],
                $this->holding($authors[$i], $this->messi),
            );
            $this->assertNotNull(DB::table('users')->where('id', $authors[$i])->value('deleted_at'));
        }

        $this->assertSame(10, $this->totalCopies($this->messi));
        $this->assertSame(11, $this->totalCopies($this->pedri));
    }

    #[Test]
    public function a_deleted_account_never_keeps_open_offers_even_if_it_was_publishing_at_the_same_time(): void
    {
        $ana = $this->createUser('ana');
        $this->giveStickers($ana, $this->messi, 11);

        $operations = array_fill(0, 10, fn () => app(PublishOffer::class)($ana, $this->messi, $this->pedri));
        $operations[] = fn () => app(DeleteAccount::class)($ana);

        $results = $this->runConcurrently($operations);

        $this->assertSame('ok', array_pop($results));
        foreach ($results as $publish) {
            // Publicó antes del borrado (y el borrado la retiró) o llegó tarde.
            $this->assertContains($publish, ['ok', 'account_deleted']);
        }

        $this->assertSame(0, DB::table('offers')->where('author_id', $ana)->where('status', 'open')->count());
        $this->assertSame(
            count(array_keys($results, 'ok', true)),
            DB::table('offers')->where('author_id', $ana)->where('status', 'withdrawn')->count(),
        );
        $this->assertSame(['quantity' => 11, 'reserved' => 0], $this->holding($ana, $this->messi));
    }
}
