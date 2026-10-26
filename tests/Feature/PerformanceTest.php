<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\Chauffeur;
use App\Models\Conversation;
use App\Models\DemandeDevis;
use App\Models\Message;
use App\Models\Metier;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Database\Seeders\MetierSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coût en requêtes des écrans les plus consultés.
 *
 * Le paragraphe 7.2 vise un chargement sous trois secondes en 3G. Le nombre
 * de requêtes compte autant que leur durée : chaque aller-retour coûte cher
 * sur un réseau à forte latence. Ces tests figent un plafond, pour qu'une
 * relation oubliée ne fasse pas glisser un écran de 4 à 40 requêtes sans
 * que personne ne le voie.
 */
class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetierSeeder::class, BadgeSeeder::class, PlanSeeder::class]);
    }

    /** Compte les requêtes émises pendant l'appel. */
    private function compterRequetes(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $action();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_le_fil_ne_coute_pas_plus_cher_avec_trente_publications(): void
    {
        $lecteur = User::factory()->create();

        foreach (User::factory()->count(10)->artisan()->create() as $artisan) {
            for ($i = 0; $i < 3; $i++) {
                Post::create([
                    'artisan_id' => $artisan->id,
                    'artisan_category' => 'Menuisier',
                    'image_url' => 'https://exemple.cg/photo.jpg',
                    'description' => 'Une réalisation.',
                ]);
            }
        }

        Sanctum::actingAs($lecteur);

        $requetes = $this->compterRequetes(fn () => $this->getJson('/api/v1/posts')->assertOk());

        // Le coût doit rester constant, pas croître avec le nombre de lignes.
        $this->assertLessThanOrEqual(
            8,
            $requetes,
            "Le fil émet {$requetes} requêtes : une relation est chargée ligne par ligne.",
        );
    }

    public function test_la_recherche_d_artisans_reste_a_cout_constant(): void
    {
        $menuisier = Metier::where('slug', 'menuisier')->first();

        foreach (Artisan::factory()->count(20)->create(['statut_validation' => Artisan::VALIDATION_VALIDE]) as $artisan) {
            $artisan->metiers()->attach($menuisier);
        }

        Sanctum::actingAs(User::factory()->create());

        $requetes = $this->compterRequetes(
            fn () => $this->getJson('/api/v1/artisans?metier=menuisier')->assertOk(),
        );

        $this->assertLessThanOrEqual(
            10,
            $requetes,
            "La recherche émet {$requetes} requêtes pour 20 artisans.",
        );
    }

    public function test_la_liste_des_conversations_ne_compte_pas_les_non_lus_un_par_un(): void
    {
        $moi = User::factory()->create();

        for ($i = 0; $i < 15; $i++) {
            $autre = User::factory()->create();
            $conversation = Conversation::create(['type' => Conversation::TYPE_CLIENT_ARTISAN]);
            $conversation->participants()->attach([$moi->id, $autre->id]);

            Message::create([
                'conversation_id' => $conversation->id,
                'expediteur_id' => $autre->id,
                'contenu' => 'Bonjour.',
            ]);
        }

        Sanctum::actingAs($moi);

        $requetes = $this->compterRequetes(
            fn () => $this->getJson('/api/v1/conversations')->assertOk(),
        );

        // Le compteur de non-lus est une sous-requête sur toute la liste,
        // pas une requête par conversation.
        $this->assertLessThanOrEqual(
            8,
            $requetes,
            "La messagerie émet {$requetes} requêtes pour 15 conversations.",
        );
    }

    public function test_les_missions_de_l_artisan_restent_a_cout_constant(): void
    {
        $artisan = Artisan::factory()->create(['statut_validation' => Artisan::VALIDATION_VALIDE]);

        foreach (User::factory()->count(20)->create() as $client) {
            DemandeDevis::factory()->create([
                'client_id' => $client->id,
                'artisan_id' => $artisan->id,
            ]);
        }

        Sanctum::actingAs($artisan->utilisateur);

        $requetes = $this->compterRequetes(fn () => $this->getJson('/api/v1/demandes')->assertOk());

        $this->assertLessThanOrEqual(10, $requetes, "L'écran des missions émet {$requetes} requêtes.");
    }

    public function test_le_tableau_de_bord_administrateur_reste_soutenable(): void
    {
        Artisan::factory()->count(15)->create();
        Chauffeur::factory()->count(10)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $requetes = $this->compterRequetes(
            fn () => $this->getJson('/api/v1/admin/tableau-de-bord')->assertOk(),
        );

        // Une vingtaine d'agrégats : le plafond est plus haut, mais borné.
        $this->assertLessThanOrEqual(
            30,
            $requetes,
            "Le tableau de bord émet {$requetes} requêtes.",
        );
    }

    public function test_l_annuaire_admin_des_artisans_reste_a_cout_constant(): void
    {
        $menuisier = Metier::where('slug', 'menuisier')->first();

        foreach (Artisan::factory()->count(25)->create() as $artisan) {
            $artisan->metiers()->attach($menuisier);
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        $requetes = $this->compterRequetes(fn () => $this->getJson('/api/v1/admin/artisans')->assertOk());

        $this->assertLessThanOrEqual(10, $requetes, "L'annuaire émet {$requetes} requêtes pour 25 artisans.");
    }
}
