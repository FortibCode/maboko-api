<?php

use App\Models\Story;
use Illuminate\Support\Facades\Schedule;

/*
| Facturation récurrente des abonnements (§4.5) : relances, prélèvements
| et clôtures. Une fois par jour, à une heure creuse.
*/
Schedule::command('maboko:renouveler-abonnements')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
| Purge des stories expirées (§5.1.4) : elles ne sont plus visibles au bout
| de 24 heures, inutile de les conserver indéfiniment.
*/
Schedule::call(function () {
    Story::where('expire_at', '<', now()->subDays(7))->delete();
})->daily()->name('purge-stories');

/*
| Sauvegarde quotidienne de la base et des fichiers privés (§7.1).
| Quatorze jours de rétention : au-delà, le disque se remplit.
*/
Schedule::command('maboko:sauvegarder')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
