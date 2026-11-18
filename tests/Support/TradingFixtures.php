<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Datos mínimos y legibles para cada test, sin depender del seeder. */
trait TradingFixtures
{
    private int $fixtureSeq = 0;

    protected function createUser(string $name = 'user'): int
    {
        $n = ++$this->fixtureSeq;

        return DB::table('users')->insertGetId([
            'name' => $name,
            'email' => "{$name}.{$n}.".uniqid().'@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createSticker(string $name = 'Cromo'): int
    {
        return DB::table('stickers')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function giveStickers(int $userId, int $stickerId, int $quantity): void
    {
        DB::table('user_stickers')->insert([
            'user_id' => $userId,
            'sticker_id' => $stickerId,
            'quantity' => $quantity,
            'reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{quantity: int, reserved: int} */
    protected function holding(int $userId, int $stickerId): array
    {
        $row = DB::table('user_stickers')->where(['user_id' => $userId, 'sticker_id' => $stickerId])->first();

        return ['quantity' => (int) ($row->quantity ?? 0), 'reserved' => (int) ($row->reserved ?? 0)];
    }

    /** @return array<string, string> */
    protected function as(int $userId): array
    {
        return ['X-User-Id' => (string) $userId, 'Accept' => 'application/json'];
    }
}
