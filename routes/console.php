<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\RafraichirMusee; // Si l'import existe

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rafraîchissement du musée
Schedule::command(RafraichirMusee::class)
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Sauvegarde quotidienne de la base et des fichiers privés (§7.1).
| Quatorze jours de rétention : au-delà, le disque se remplit.
*/
Schedule::command('maboko:sauvegarder')
    ->dailyAt('04:00') // Ajuste l'heure si besoin
    ->withoutOverlapping()
    ->onOneServer();