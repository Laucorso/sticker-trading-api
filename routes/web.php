<?php

use Illuminate\Support\Facades\Route;

// Página de demostración: abre /demo?user=1 y /demo?user=2 en dos pestañas.
Route::redirect('/', '/demo?user=1');
Route::view('/demo', 'demo')->name('demo');
