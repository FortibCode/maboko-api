<?php

namespace Tests\Feature\Confiance;

use App\Models\Artisan;
use App\Models\Badge;
use App\Models\User;
use App\Services\MoteurBadges;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoteurBadgesTest extends TestCase
{
    use RefreshDatabase;

    private MoteurBadges $moteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
        $this->moteur = app(MoteurBadges::class);
    }

    private function badges(Artisan $artisan): array
    {
        return $artisan->fresh('badges')->badges->pluck('slug')->sort()->values()->all();
    }

    public function test_un_profil_recent_obtient_le_badge_nouveau(): void
    {
        $artisan = Artisan::factory()->create();

        $this->moteur->reevaluer($artisan);

        $this->assertContains('nouveau', $this->badges($artisan));
    }

    public function test_le_badge_nouveau_disparait_apres_un_mois(): void
    {
        $artisan = Artisan::factory()->create();
        $this->moteur->reevaluer($artisan);
        $this->assertContains('nouveau', $this->badges($artisan));

        $this->travel(40)->days();
        $this->moteur->reevaluer($artisan->fresh('badges'));

        $this->assertNotContains('nouveau', $this->badges($artisan));
    }

    public function test_confirme_s_obtient_avec_cinq_missions_et_une_bonne_note(): void
    {
        $artisan = Artisan::factory()->create([
            'nb_missions_terminees' => 5,
            'note_moyenne' => 4.0,
        ]);

        $this->moteur->reevaluer($artisan);

        $this->assertContains('confirme', $this->badges($artisan));
    }

    public function test_confirme_est_refuse_si_la_note_est_trop_basse(): void
    {
        $artisan = Artisan::factory()->create([
            'nb_missions_terminees' => 20,
            'note_moyenne' => 2.8,
        ]);

        $this->moteur->reevaluer($artisan);

        $this->assertNotContains('confirme', $this->badges($artisan));
    }

    public function test_maitre_artisan_exige_aussi_de_l_anciennete(): void
    {
        $artisan = Artisan::factory()->create([
            'nb_missions_terminees' => 60,
            'note_moyenne' => 4.7,
        ]);

        // Volume et note suffisants, mais le compte vient d'être créé.
        $this->moteur->reevaluer($artisan);
        $this->assertNotContains('maitre-artisan', $this->badges($artisan));

        $this->travel(200)->days();
        $this->moteur->reevaluer($artisan->fresh('badges'));

        $this->assertContains('maitre-artisan', $this->badges($artisan));
    }

    public function test_recommande_exige_un_volume_d_avis(): void
    {
        $artisan = Artisan::factory()->create(['note_moyenne' => 4.9, 'nb_avis' => 5]);
        $this->moteur->reevaluer($artisan);
        $this->assertNotContains('recommande', $this->badges($artisan));

        $artisan->update(['nb_avis' => 25]);
        $this->moteur->reevaluer($artisan->fresh('badges'));
        $this->assertContains('recommande', $this->badges($artisan));
    }

    public function test_les_badges_manuels_ne_s_accordent_jamais_seuls(): void
    {
        $artisan = Artisan::factory()->create([
            'nb_missions_terminees' => 500,
            'note_moyenne' => 5.0,
            'nb_avis' => 300,
            'local_professionnel' => true,
        ]);
        $this->travel(400)->days();

        $this->moteur->reevaluer($artisan);

        $obtenus = $this->badges($artisan);
        foreach (['profil-verifie', 'certifie-maboko', 'atelier-reconnu'] as $manuel) {
            $this->assertNotContains($manuel, $obtenus, "Le badge « {$manuel} » s'est accordé sans validation humaine.");
        }
    }

    public function test_l_administration_attribue_un_badge_manuel(): void
    {
        $artisan = Artisan::factory()->create();
        $admin = User::factory()->admin()->create();
        $badge = Badge::where('slug', 'profil-verifie')->first();

        $this->assertTrue($this->moteur->attribuerManuellement($artisan, $badge, $admin->id));

        $this->assertContains('profil-verifie', $this->badges($artisan));
        $this->assertDatabaseHas('artisan_badge', [
            'artisan_id' => $artisan->id,
            'badge_id' => $badge->id,
            'attribue_par' => $admin->id,
        ]);
    }

    public function test_un_badge_deja_obtenu_n_est_pas_attribue_deux_fois(): void
    {
        $artisan = Artisan::factory()->create();
        $badge = Badge::where('slug', 'certifie-maboko')->first();

        $this->assertTrue($this->moteur->attribuerManuellement($artisan, $badge));
        $this->assertFalse($this->moteur->attribuerManuellement($artisan, $badge));

        $this->assertSame(1, $artisan->badges()->count());
    }

    public function test_un_badge_fait_monter_le_score_de_classement(): void
    {
        $artisan = Artisan::factory()->create([
            'nb_missions_terminees' => 10,
            'note_moyenne' => 4.5,
            'nb_avis' => 20,
        ]);

        $this->moteur->reevaluer($artisan);
        $avant = (float) $artisan->fresh()->score_classement;

        $this->moteur->attribuerManuellement($artisan->fresh('badges'), Badge::where('slug', 'certifie-maboko')->first());

        $this->assertGreaterThan($avant, (float) $artisan->fresh()->score_classement);
    }

    public function test_retirer_un_badge_fait_baisser_le_score(): void
    {
        $artisan = Artisan::factory()->create(['nb_missions_terminees' => 10, 'note_moyenne' => 4.5, 'nb_avis' => 20]);
        $badge = Badge::where('slug', 'certifie-maboko')->first();

        $this->moteur->attribuerManuellement($artisan, $badge);
        $avec = (float) $artisan->fresh()->score_classement;

        $this->moteur->retirer($artisan->fresh('badges'), $badge);

        $this->assertLessThan($avec, (float) $artisan->fresh()->score_classement);
    }
}
