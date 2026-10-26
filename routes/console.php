<?php

use App\Console\Commands\RafraichirMusee;
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
<<<<<<< HEAD
| Musée du savoir-faire (§5.1.7) : propose chaque nuit les artisans qui
| viennent de passer les seuils (note ≥ 4.5, ≥ 10 missions terminées).
| L'administrateur valide ensuite manuellement chaque proposition.
*/
Schedule::command(RafraichirMusee::class)
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
=======
| Sauvegarde quotidienne de la base et des fichiers privés (§7.1).
| Quatorze jours de rétention : au-delà, le disque se remplit.
*/
Schedule::command('maboko:sauvegarder')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
