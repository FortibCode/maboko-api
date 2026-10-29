<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo réelle d'un métier (§5.1.5).
     *
     * Le référentiel affichait jusqu'ici une icône Material dessinée par
     * l'application. Une photo de chantier ou d'atelier dit mieux ce
     * qu'un métier recouvre : l'administration peut en déposer une
     * depuis le back-office.
     *
     * L'URL est stockée telle quelle — le fichier lui-même vit sur le
     * disque configuré (local en développement, S3 en production).
     */
    public function up(): void
    {
        // La colonne existe deja si 2026_09_10_160000_ajouter_image_aux_metiers
        // est passee : les deux branches fusionnees l'ajoutaient chacune.
        if (Schema::hasColumn('metiers', 'image_url')) {
            return;
        }

        Schema::table('metiers', function (Blueprint $table) {
            $table->string('image_url', 500)
                ->nullable()
                ->after('icone');
        });
    }

    public function down(): void
    {
        Schema::table('metiers', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
