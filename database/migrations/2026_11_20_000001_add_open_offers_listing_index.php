<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El listado filtra por status y ordena por id DESC. Con solo (status, expires_at)
 * MySQL tenía que ordenar todas las ofertas abiertas para devolver una página;
 * con (status, id) recorre por id y para al llenarla. (status, expires_at) se
 * mantiene para el barrido de caducadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->index(['status', 'id'], 'offers_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex('offers_status_id_index');
        });
    }
};
