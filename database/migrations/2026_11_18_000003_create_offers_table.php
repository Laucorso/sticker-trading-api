<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('offered_sticker_id')->constrained('stickers');
            $table->foreignId('requested_sticker_id')->constrained('stickers');
            $table->string('status', 16);
            $table->dateTime('expires_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            // Listado de abiertas y barrido de caducadas.
            $table->index(['status', 'expires_at']);
            // Conteo semanal de intercambios por pareja (se consulta en ambos sentidos).
            $table->index(['author_id', 'accepted_by', 'closed_at']);
        });

        DB::statement('ALTER TABLE offers ADD CONSTRAINT chk_distinct_stickers CHECK (offered_sticker_id <> requested_sticker_id)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT chk_not_self_accepted CHECK (accepted_by IS NULL OR accepted_by <> author_id)');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT chk_accepted_has_acceptor CHECK ((status = 'accepted') = (accepted_by IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
