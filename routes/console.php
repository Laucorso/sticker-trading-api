<?php

use Illuminate\Support\Facades\Schedule;

// Cada minuto: como mucho un minuto de retraso en liberar el cromo. Mientras tanto
// la oferta ya no se puede aceptar ni aparece en el listado (lo decide expires_at).
// onOneServer + withoutOverlapping: con varias réplicas solo barre una.
Schedule::command('offers:expire')->everyMinute()->withoutOverlapping()->onOneServer();
