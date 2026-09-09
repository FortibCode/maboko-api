<?php

namespace Database\Seeders;

use App\Models\Metier;
use Illuminate\Database\Seeder;

/**
 * Referentiel des metiers manuels (§4.1 et §5.1.5).
 */
class MetierSeeder extends Seeder
{
    public function run(): void
    {
        $metiers = [
            ['Maçon', 'macon', 'Construction, fondations, élévation de murs.'],
            ['Plombier', 'plombier', 'Installation et réparation sanitaire, canalisations.'],
            ['Menuisier', 'menuisier', 'Mobilier, portes, fenêtres, charpente bois.'],
            ['Couturier', 'couturier', 'Confection sur mesure, retouches, pagne.'],
            ['Mécanicien', 'mecanicien', 'Entretien et réparation automobile et moto.'],
            ['Électricien', 'electricien', 'Installation électrique, dépannage, tableaux.'],
            ['Peintre', 'peintre', 'Peinture intérieure et extérieure, revêtements.'],
            ['Frigoriste', 'frigoriste', 'Climatisation, réfrigération, entretien.'],
            ['Carreleur', 'carreleur', 'Pose de carrelage, faïence, dallage.'],
            ['Soudeur', 'soudeur', 'Métallerie, portails, grilles, structures.'],
            ['Vitrier', 'vitrier', 'Pose et remplacement de vitrages.'],
            ['Plaquiste', 'plaquiste', 'Cloisons, faux plafonds, isolation.'],
            ['Charpentier', 'charpentier', 'Charpente, toiture, ossature bois.'],
            ['Couvreur', 'couvreur', 'Toitures, tôles, étanchéité.'],
            ['Serrurier', 'serrurier', 'Serrures, blindage, ouverture de porte.'],
            ['Jardinier', 'jardinier', 'Entretien d espaces verts, élagage.'],
            ['Coiffeur', 'coiffeur', 'Coiffure homme, femme et enfant.'],
            ['Cordonnier', 'cordonnier', 'Réparation de chaussures et maroquinerie.'],
            ['Tapissier', 'tapissier', 'Réfection de sièges, rideaux, literie.'],
            ['Ébéniste', 'ebeniste', 'Mobilier d art, marqueterie, restauration.'],
            ['Tôlier', 'tolier', 'Carrosserie, redressage, débosselage.'],
            ['Informaticien', 'informaticien', 'Dépannage, réseau, installation de matériel.'],
        ];

        foreach ($metiers as $ordre => [$nom, $slug, $description]) {
            Metier::updateOrCreate(
                ['slug' => $slug],
                ['nom' => $nom, 'description' => $description, 'icone' => $slug, 'ordre' => $ordre, 'actif' => true],
            );
        }
    }
}
