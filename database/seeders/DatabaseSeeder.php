<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 4 usuarios y 12 cromos. Cada usuario tiene repetidos distintos para que la demo
 * funcione de entrada: p. ej. Ana (1) ofrece el cromo 1 a cambio del 2, y Bruno (2)
 * tiene el 2 repetido.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotente: el contenedor lo ejecuta en cada arranque.
        if (DB::table('users')->exists()) {
            return;
        }

        $now = now();
        $password = Hash::make('password');

        foreach (['Ana', 'Bruno', 'Carla', 'Dani'] as $i => $name) {
            DB::table('users')->insert([
                'id' => $i + 1,
                'name' => $name,
                'email' => strtolower($name).'@example.test',
                'password' => $password,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $positions = ['Portero', 'Defensa', 'Lateral', 'Central', 'Pivote', 'Interior', 'Mediapunta', 'Extremo', 'Delantero', 'Escudo', 'Estadio', 'Entrenador'];
        foreach ($positions as $i => $position) {
            DB::table('stickers')->insert([
                'id' => $i + 1,
                'name' => sprintf('#%02d %s', $i + 1, $position),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // [user_id => [sticker_id => copias]]
        $collections = [
            1 => [1 => 3, 3 => 1, 5 => 2, 7 => 1, 9 => 2],
            2 => [2 => 3, 4 => 1, 6 => 2, 8 => 1, 10 => 2],
            3 => [1 => 1, 2 => 2, 3 => 3, 11 => 2],
            4 => [4 => 3, 5 => 1, 6 => 1, 12 => 2],
        ];

        foreach ($collections as $userId => $stickers) {
            foreach ($stickers as $stickerId => $quantity) {
                DB::table('user_stickers')->insert([
                    'user_id' => $userId,
                    'sticker_id' => $stickerId,
                    'quantity' => $quantity,
                    'reserved' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
