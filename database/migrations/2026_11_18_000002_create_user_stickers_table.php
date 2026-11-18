<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stickers', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sticker_id')->constrained();
            $table->unsignedInteger('quantity');
            // Copias comprometidas en ofertas abiertas.
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();

            $table->primary(['user_id', 'sticker_id']);
        });

        // Última línea de defensa de la regla "nunca te quedas sin la última copia":
        // aunque un bug del código se saltara el dominio, la BD rechaza el UPDATE.
        DB::statement('ALTER TABLE user_stickers ADD CONSTRAINT chk_keeps_last_copy CHECK (reserved < quantity OR (quantity = 0 AND reserved = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stickers');
    }
};
