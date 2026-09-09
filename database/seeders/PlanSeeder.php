<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Les quatre formules d'abonnement du §4.5.
 * Montants en francs CFA.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => Plan::GRATUIT,
                'nom' => 'Gratuit',
                'description' => 'Visibilité de base sur la plateforme.',
                'prix_mensuel' => 0,
                'prix_annuel' => 0,
                'boost_classement' => 1.0,
                'avantages' => [
                    'Profil public avec portfolio',
                    'Réception des demandes de devis',
                    'Badges automatiques',
                ],
            ],
            [
                'slug' => Plan::PRO,
                'nom' => 'Pro',
                'description' => 'Meilleur positionnement dans les résultats de recherche.',
                'prix_mensuel' => 5000,
                'prix_annuel' => 50000,
                'boost_classement' => 1.5,
                'avantages' => [
                    'Tous les avantages du plan Gratuit',
                    'Positionnement renforcé dans la recherche',
                    'Statistiques de consultation du profil',
                    'Portfolio illimité',
                ],
            ],
            [
                'slug' => Plan::PREMIUM,
                'nom' => 'Premium',
                'description' => 'Mise en avant sur la page d accueil et statistiques avancées.',
                'prix_mensuel' => 15000,
                'prix_annuel' => 150000,
                'boost_classement' => 2.0,
                'avantages' => [
                    'Tous les avantages du plan Pro',
                    'Mise en avant sur la page d accueil',
                    'Statistiques avancées',
                    'Support prioritaire',
                ],
            ],
            [
                'slug' => Plan::ENTREPRISE,
                'nom' => 'Entreprise',
                'description' => 'Pour les structures employant plusieurs artisans.',
                'prix_mensuel' => 40000,
                'prix_annuel' => 400000,
                'boost_classement' => 2.5,
                'avantages' => [
                    'Tous les avantages du plan Premium',
                    'Plusieurs artisans sous une même enseigne',
                    'Tableau de bord consolidé',
                    'Gestionnaire de compte dédié',
                ],
            ],
        ];

        foreach ($plans as $ordre => $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], [...$plan, 'ordre' => $ordre, 'actif' => true]);
        }
    }
}
