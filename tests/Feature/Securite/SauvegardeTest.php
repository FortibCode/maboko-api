<?php

namespace Tests\Feature\Securite;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sauvegarde et restauration (§7.1).
 *
 * Une sauvegarde qu'on n'a jamais restaurée n'est pas une sauvegarde : ces
 * tests vérifient le cycle complet, pas seulement qu'un fichier a été écrit.
 *
 * Le test travaille sur une base de fichier dédiée, sans RefreshDatabase :
 * la commande copie un fichier, ce qu'une base en mémoire ne permet pas.
 */
class SauvegardeTest extends TestCase
{
    private string $dossier;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/sauvegardes');
        File::deleteDirectory($this->dossier);
        File::ensureDirectoryExists($this->dossier);

        $this->base = $this->dossier.'/source.sqlite';
        touch($this->base);

        config([
            'database.default' => 'sauvegarde_test',
            'database.connections.sauvegarde_test' => [
                'driver' => 'sqlite',
                'database' => $this->base,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('sauvegarde_test');
        DB::connection('sauvegarde_test')->statement(
            'create table temoins (id integer primary key autoincrement, valeur text)',
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect('sauvegarde_test');
        File::deleteDirectory($this->dossier);
        parent::tearDown();
    }

    private function sauvegarder(array $options = []): int
    {
        return Artisan::call('maboko:sauvegarder', array_merge([
            '--destination' => $this->dossier,
        ], $options));
    }

    public function test_la_sauvegarde_produit_une_copie_lisible(): void
    {
        DB::connection('sauvegarde_test')->table('temoins')->insert(['valeur' => 'avant-sauvegarde']);

        $this->assertSame(0, $this->sauvegarder());

        $copies = glob($this->dossier.'/base_*.sqlite');
        $this->assertCount(1, $copies);

        // Le cycle complet : on relit la copie et on y retrouve la donnée.
        $lecture = (new \PDO('sqlite:'.$copies[0]))
            ->query('select valeur from temoins')
            ->fetchColumn();

        $this->assertSame('avant-sauvegarde', $lecture);
    }

    public function test_la_restauration_retrouve_les_donnees_apres_une_perte(): void
    {
        DB::connection('sauvegarde_test')->table('temoins')->insert(['valeur' => 'donnee-critique']);

        $this->sauvegarder();
        $copie = glob($this->dossier.'/base_*.sqlite')[0];

        // La base est perdue.
        DB::disconnect('sauvegarde_test');
        File::delete($this->base);
        $this->assertFileDoesNotExist($this->base);

        // On restaure depuis la sauvegarde.
        File::copy($copie, $this->base);
        DB::purge('sauvegarde_test');

        $this->assertSame(
            'donnee-critique',
            DB::connection('sauvegarde_test')->table('temoins')->value('valeur'),
        );
    }

    public function test_les_fichiers_prives_sont_archives(): void
    {
        $dossierPieces = storage_path('app/private/identites');
        File::ensureDirectoryExists($dossierPieces);
        File::put($dossierPieces.'/piece-test.chiffre', 'contenu chiffré');

        try {
            $this->sauvegarder();

            $archives = glob($this->dossier.'/fichiers_*.zip');
            $this->assertCount(1, $archives, 'Aucune archive de fichiers produite.');

            // Une sauvegarde de la base seule laisserait des références vers
            // des fichiers disparus.
            $zip = new \ZipArchive;
            $zip->open($archives[0]);

            $noms = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $noms[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $this->assertContains('identites/piece-test.chiffre', $noms);
        } finally {
            File::delete($dossierPieces.'/piece-test.chiffre');
        }
    }

    public function test_les_anciennes_sauvegardes_sont_purgees(): void
    {
        // Sans purge, le disque se remplit jusqu'à l'arrêt du serveur — une
        // panne d'autant plus pénible qu'elle vient du dispositif censé
        // protéger les données.
        foreach (range(1, 6) as $index) {
            $chemin = $this->dossier.sprintf('/base_2026-09-%02d_020000.sqlite', $index);
            File::put($chemin, 'x');
            touch($chemin, time() - (10 - $index) * 86400);
        }

        $this->assertSame(0, $this->sauvegarder(['--conserver' => 3]));

        $this->assertCount(3, glob($this->dossier.'/base_*.sqlite'));
    }
}
