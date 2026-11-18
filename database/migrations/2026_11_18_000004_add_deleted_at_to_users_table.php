<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuentas no se borran físicamente: se anonimizan y se marcan con deleted_at
 * para conservar el historial de intercambios de la otra parte (offers.author_id
 * y offers.accepted_by siguen apuntando a una fila que existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
