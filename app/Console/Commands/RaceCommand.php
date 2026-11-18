<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Trading\Application\PublishOffer;

/**
 * Script de concurrencia contra la API real: lanza N peticiones HTTP simultáneas
 * (curl multi) y muestra cuántas salen adelante. Crea sus propios usuarios para
 * no interferir con los datos de la demo.
 */
class RaceCommand extends Command
{
    protected $signature = 'trading:race
        {--requests=25 : Peticiones simultáneas por escenario}
        {--url= : URL base de la API (por defecto APP_URL)}';

    protected $description = 'Lanza aceptaciones simultáneas sobre la misma oferta y verifica que solo una sale adelante';

    public function handle(PublishOffer $publishOffer): int
    {
        $n = (int) $this->option('requests');
        $url = rtrim($this->option('url') ?: config('app.url'), '/');
        $run = Str::lower(Str::random(6));
        [$offered, $requested] = $this->stickers();

        $this->info("API: {$url} · {$n} peticiones simultáneas por escenario");

        // Escenario 1: N usuarios distintos aceptan la MISMA oferta.
        $author = $this->user("race-{$run}-author", [$offered => 2]);
        $acceptors = array_map(fn ($i) => $this->user("race-{$run}-acceptor{$i}", [$requested => 2]), range(1, $n));
        $offer = $publishOffer($author, $offered, $requested);

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $user) => $pool->withHeaders(['X-User-Id' => (string) $user, 'Accept' => 'application/json'])
                ->post("{$url}/api/offers/{$offer->id()}/accept"),
            $acceptors,
        ));

        $row = DB::table('offers')->find($offer->id());
        $ok1 = $this->report("1) {$n} usuarios aceptan la oferta #{$offer->id()}", $responses, expectedOk: 1)
            && $row->status === 'accepted';
        $this->line("   Estado final: {$row->status}, aceptada por el usuario {$row->accepted_by}");

        // Escenario 2: el MISMO usuario acepta N ofertas distintas teniendo una sola repetida.
        $greedy = $this->user("race-{$run}-greedy", [$requested => 2]);
        $offers = array_map(
            fn ($i) => $publishOffer($this->user("race-{$run}-author{$i}", [$offered => 2]), $offered, $requested)->id(),
            range(1, $n),
        );

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $offerId) => $pool->withHeaders(['X-User-Id' => (string) $greedy, 'Accept' => 'application/json'])
                ->post("{$url}/api/offers/{$offerId}/accept"),
            $offers,
        ));

        $left = (int) DB::table('user_stickers')->where(['user_id' => $greedy, 'sticker_id' => $requested])->value('quantity');
        $ok2 = $this->report("2) Un usuario con 1 repetida acepta {$n} ofertas a la vez", $responses, expectedOk: 1)
            && $left === 1;
        $this->line("   Copias que le quedan del cromo entregado: {$left} (nunca menos de 1)");

        $this->newLine();
        $ok1 && $ok2
            ? $this->info('✔ Las reglas se cumplen con peticiones simultáneas')
            : $this->error('✘ Se ha violado alguna regla');

        return $ok1 && $ok2 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, Response|\Throwable> $responses */
    private function report(string $title, array $responses, int $expectedOk): bool
    {
        $outcomes = collect($responses)->map(fn ($r) => $r instanceof Response
            ? $r->status().' '.($r->json('error') ?? $r->json('data.status'))
            : 'error de red: '.$r->getMessage());

        $this->newLine();
        $this->line("<comment>{$title}</comment>");
        $this->table(['Respuesta', 'Veces'], $outcomes->countBy()->sortKeys()->map(fn ($c, $k) => [$k, $c])->values());

        return $outcomes->filter(fn ($o) => str_starts_with($o, '200'))->count() === $expectedOk;
    }

    /** @return array{int, int} */
    private function stickers(): array
    {
        $ids = DB::table('stickers')->orderBy('id')->limit(2)->pluck('id')->all();

        if (count($ids) < 2) {
            throw new \RuntimeException('No hay cromos: ejecuta antes php artisan migrate --seed');
        }

        return $ids;
    }

    /** @param array<int, int> $stickers */
    private function user(string $name, array $stickers): int
    {
        $id = DB::table('users')->insertGetId([
            'name' => $name, 'email' => "{$name}@race.test", 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($stickers as $stickerId => $quantity) {
            DB::table('user_stickers')->insert([
                'user_id' => $id, 'sticker_id' => $stickerId, 'quantity' => $quantity, 'reserved' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
