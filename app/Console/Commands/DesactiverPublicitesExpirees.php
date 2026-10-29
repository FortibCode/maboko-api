<?php

namespace App\Console\Commands;

use App\Models\Publicite;
use Illuminate\Console\Command;

/**
 * Désactive les publicités dont la date de fin est dépassée.
 *
 * Les publicités sans date de fin restent actives tant que l'admin
 * ne les désactive pas manuellement. La commande évite d'oublier une
 * campagne terminée.
 */
class DesactiverPublicitesExpirees extends Command
{
    protected $signature = 'publicites:desactiver-expirees';

    protected $description = 'Désactive les publicités dont la date de fin est passée.';

    public function handle(): int
    {
        $nombre = Publicite::query()
            ->where('actif', true)
            ->whereNotNull('date_fin')
            ->where('date_fin', '<', now())
            ->update(['actif' => false]);

        $this->info("✓ {$nombre} publicité(s) désactivée(s).");

        return self::SUCCESS;
    }
}
