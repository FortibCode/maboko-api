<?php

namespace Database\Seeders;

use App\Models\Abonnement;
use App\Models\Artisan;
use App\Models\Badge;
use App\Models\Chauffeur;
use App\Models\Metier;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Donnees de reference, indispensables au fonctionnement de la plateforme.
        $this->call([
            MetierSeeder::class,
            BadgeSeeder::class,
            PlanSeeder::class,
            TarifSeeder::class,
        ]);

        // Comptes de demonstration, mot de passe commun : "password"
        $this->compteDemo('Admin', 'Maboko', 'admin@maboko.cg', '+242060000001', User::ROLE_ADMIN);
        $this->compteDemo('Makaya', 'Jean', 'client@maboko.cg', '+242060000002', User::ROLE_CLIENT);

        $artisanDemo = $this->compteDemo('Nkodia', 'Pascal', 'artisan@maboko.cg', '+242060000003', User::ROLE_ARTISAN);
        $ficheArtisan = Artisan::create([
            'utilisateur_id' => $artisanDemo->id,
            'specialite' => 'Menuisier',
            'adresse' => 'Bacongo, Brazzaville',
            'latitude' => -4.2894000,
            'longitude' => 15.2429000,
            'bio' => 'Menuisier depuis quinze ans a Brazzaville. Mobilier sur mesure en bois massif.',
            'zone_intervention' => 'Brazzaville et peripherie',
            'statut_validation' => Artisan::VALIDATION_VALIDE,
            'valide_at' => now(),
        ]);

        $ficheArtisan->metiers()->attach(
            Metier::whereIn('slug', ['menuisier', 'ebeniste'])->pluck('id'),
            ['niveau' => 'expert', 'principal' => true],
        );

        $ficheArtisan->badges()->attach(
            Badge::whereIn('slug', ['confirme', 'profil-verifie'])->pluck('id'),
            ['obtenu_at' => now()],
        );

        Abonnement::create([
            'artisan_id' => $ficheArtisan->id,
            'plan_id' => Plan::where('slug', Plan::PRO)->value('id'),
            'periodicite' => 'mensuel',
            'debut' => now()->toDateString(),
            'fin' => now()->addMonth()->toDateString(),
            'statut' => Abonnement::STATUT_ACTIF,
        ]);

        $chauffeurDemo = $this->compteDemo('Bouiti', 'Alain', 'chauffeur@maboko.cg', '+242060000004', User::ROLE_CHAUFFEUR);
        Chauffeur::create([
            'utilisateur_id' => $chauffeurDemo->id,
            'permis_conduire' => 'PERM-00001',
            'vehicule_modele' => 'Toyota Corolla',
            'plaque_immatriculation' => 'BZV-001-CG',
            'disponibilite' => true,
            'type_vehicule' => Chauffeur::VEHICULE_VOITURE,
            'statut_validation' => Artisan::VALIDATION_VALIDE,
            'en_ligne' => true,
            'valide_at' => now(),
        ]);

        // Jeu de donnees plus large pour eprouver les listes et la recherche
        User::factory(15)->create();
        Chauffeur::factory(6)->create();

        Artisan::factory(12)->create();

        // Requete typee : la collection renvoyee par une fabrique est generique.
        $metiers = Metier::pluck('id');
        foreach (Artisan::whereNull('valide_at')->get() as $artisan) {
            $artisan->metiers()->attach($metiers->random(), ['principal' => true]);
            $artisan->update([
                'statut_validation' => Artisan::VALIDATION_VALIDE,
                'valide_at' => now(),
                'note_moyenne' => fake()->randomFloat(2, 3.2, 5),
                'nb_avis' => fake()->numberBetween(0, 40),
                'nb_missions_terminees' => fake()->numberBetween(0, 60),
            ]);
        }
    }

    private function compteDemo(string $nom, string $prenom, string $email, string $telephone, string $role): User
    {
        return User::create([
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => $telephone,
            'password' => 'password',
            'role' => $role,
            'is_verified' => true,
            'statut' => User::STATUT_ACTIF,
            'ville' => 'Brazzaville',
        ]);
    }
}
