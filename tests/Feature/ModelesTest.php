<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cohérence entre les modèles et le schéma.
 *
 * Une colonne absente du « fillable » est silencieusement ignorée à
 * l'écriture : Laravel ne lève aucune erreur, la valeur disparaît. Ce défaut
 * est apparu cinq fois pendant la reprise, chaque fois parce qu'une migration
 * ajoutait une colonne sans que le modèle suive. Ce test l'attrape désormais
 * au premier oubli.
 */
class ModelesTest extends TestCase
{
    use RefreshDatabase;

    /** Colonnes gérées par le framework, jamais assignées en masse. */
    private const TECHNIQUES = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'remember_token', 'email_verified_at',
    ];

    /**
     * Exceptions assumées : colonnes volontairement non assignables en masse.
     *
     * @var array<string, array<int, string>>
     */
    private const EXCEPTIONS = [];

    public function test_chaque_colonne_est_assignable_ou_explicitement_exclue(): void
    {
        $manquantes = [];

        foreach (glob(app_path('Models/*.php')) as $fichier) {
            $classe = 'App\\Models\\'.basename($fichier, '.php');

            if (! class_exists($classe)) {
                continue;
            }

            $modele = new $classe;

            if (! $modele instanceof Model) {
                continue;
            }

            $table = $modele->getTable();
            $fillable = $modele->getFillable();

            // Un modèle sans fillable est protégé autrement : hors sujet ici.
            if (empty($fillable) || ! Schema::hasTable($table)) {
                continue;
            }

            $oubliees = array_diff(
                Schema::getColumnListing($table),
                $fillable,
                self::TECHNIQUES,
                self::EXCEPTIONS[$classe] ?? [],
            );

            if ($oubliees) {
                $manquantes[basename($fichier, '.php')] = array_values($oubliees);
            }
        }

        $this->assertSame(
            [],
            $manquantes,
            "Colonnes absentes du fillable — elles seront ignorées silencieusement à l'écriture :\n"
            .collect($manquantes)->map(fn ($c, $m) => "  {$m} : ".implode(', ', $c))->implode("\n"),
        );
    }
}
