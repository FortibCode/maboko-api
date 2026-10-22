<?php

namespace App\Console\Commands;

use App\Models\MuseeSavoirFaire;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Calcule les candidats au musée du savoir-faire.
 *
 * Critères :
 *  - rôle artisan, compte actif, fiche validée ;
 *  - note moyenne ≥ 4.5 ;
 *  - au moins 10 missions terminées.
 *
 * Programmée chaque nuit dans routes/console.php (Laravel 11+) ou dans
 * le Kernel (Laravel 10 et antérieur). Peut être lancée manuellement :
 *   php artisan musee:rafraichir
 */
class RafraichirMusee extends Command
{
    protected $signature = 'musee:rafraichir';

    protected $description = 'Recalcule les candidats au musée du savoir-faire.';

    public function handle(): int
    {
        $this->info('Calcul des candidats au musée…');

        $artisans = User::query()
            ->where('role', User::ROLE_ARTISAN)
            ->where('statut', User::STATUT_ACTIF)
            ->whereHas('artisan', function ($q) {
                $q->where('statut_validation', 'valide')
                  ->where('note_moyenne', '>=', 4.5)
                  ->where('nb_missions_terminees', '>=', 10);
            })
            ->pluck('id');

        $crees = 0;
        $recycles = 0;

        foreach ($artisans as $artisanId) {
            $existant = MuseeSavoirFaire::where('artisan_id', $artisanId)->first();

            if (! $existant) {
                MuseeSavoirFaire::create([
                    'artisan_id' => $artisanId,
                    'statut' => MuseeSavoirFaire::STATUT_CANDIDAT,
                ]);
                $crees++;
                continue;
            }

            // Un candidat ignoré depuis 30 jours redevient proposable.
            if ($existant->statut === MuseeSavoirFaire::STATUT_IGNORE
                && $existant->ignore_jusqua?->isPast()) {
                $existant->update(['statut' => MuseeSavoirFaire::STATUT_CANDIDAT]);
                $recycles++;
            }
        }

        $this->info("✓ {$crees} nouveaux candidats.");
        $this->info("✓ {$recycles} candidats recyclés.");

        return self::SUCCESS;
    }
}