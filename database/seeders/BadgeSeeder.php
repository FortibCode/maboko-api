<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

/**
 * Les sept badges du §4.5, avec leurs conditions d'attribution.
 *
 * « regle_attribution » est lue par le moteur de progression : un badge
 * automatique est accorde des que toutes ses conditions sont remplies.
 * Les badges non automatiques passent par une validation humaine.
 */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            [
                'slug' => 'nouveau',
                'nom' => 'Nouveau',
                'description' => 'Profil récemment inscrit sur la plateforme.',
                'automatique' => true,
                'poids_classement' => 0,
                'regle_attribution' => ['anciennete_jours_max' => 30],
            ],
            [
                'slug' => 'confirme',
                'nom' => 'Confirmé',
                'description' => 'Un premier volume de missions réalisées avec succès.',
                'automatique' => true,
                'poids_classement' => 10,
                'regle_attribution' => ['missions_terminees_min' => 5, 'note_moyenne_min' => 3.5],
            ],
            [
                'slug' => 'maitre-artisan',
                'nom' => 'Maître artisan',
                'description' => 'Parmi les professionnels les plus expérimentés et les mieux notés.',
                'automatique' => true,
                'poids_classement' => 30,
                'regle_attribution' => [
                    'missions_terminees_min' => 50,
                    'note_moyenne_min' => 4.5,
                    'anciennete_jours_min' => 180,
                ],
            ],
            [
                'slug' => 'profil-verifie',
                'nom' => 'Profil vérifié',
                'description' => 'Identité contrôlée par l équipe Maboko.',
                'automatique' => false,
                'poids_classement' => 20,
                'regle_attribution' => ['verification_identite' => 'valide'],
            ],
            [
                'slug' => 'certifie-maboko',
                'nom' => 'Certifié Maboko',
                'description' => 'Compétences validées après un examen approfondi.',
                'automatique' => false,
                'poids_classement' => 40,
                'regle_attribution' => null,
            ],
            [
                'slug' => 'recommande',
                'nom' => 'Recommandé',
                'description' => 'Plébiscité par ses clients.',
                'automatique' => true,
                'poids_classement' => 25,
                'regle_attribution' => ['note_moyenne_min' => 4.8, 'nb_avis_min' => 20],
            ],
            [
                'slug' => 'atelier-reconnu',
                'nom' => 'Atelier reconnu',
                'description' => 'Dispose d un local professionnel identifié.',
                'automatique' => false,
                'poids_classement' => 15,
                'regle_attribution' => ['local_professionnel' => true],
            ],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(['slug' => $badge['slug']], [...$badge, 'icone' => $badge['slug']]);
        }
    }
}
