<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allô Chauffeur (§4.3, §5.3). La table « courses » ne portait qu'un lieu de
 * depart et d'arrivee en texte libre : ni coordonnees, ni distance, ni tarif
 * calcule, ni suivi. Rien ne permettait d'apparier un client et un chauffeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Grille tarifaire par type de vehicule.
        Schema::create('tarifs', function (Blueprint $table) {
            $table->id();
            $table->string('type_vehicule')->unique(); // moto, voiture
            $table->decimal('prix_base', 10, 2);
            $table->decimal('prix_km', 10, 2);
            $table->decimal('prix_minute', 10, 2)->default(0);
            $table->decimal('course_minimum', 10, 2);
            $table->decimal('taux_commission', 5, 2)->default(15);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->decimal('depart_latitude', 10, 7)->nullable();
            $table->decimal('depart_longitude', 10, 7)->nullable();
            $table->decimal('arrivee_latitude', 10, 7)->nullable();
            $table->decimal('arrivee_longitude', 10, 7)->nullable();

            $table->string('type_vehicule')->default('moto');
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedSmallInteger('duree_estimee_min')->nullable();
            $table->decimal('tarif_estime', 10, 2)->nullable();
            $table->decimal('tarif_final', 10, 2)->nullable();

            $table->string('annulee_par')->nullable(); // client, chauffeur, systeme
            $table->text('motif_annulation')->nullable();

            $table->timestamp('acceptee_at')->nullable();
            $table->timestamp('prise_en_charge_at')->nullable();
            $table->timestamp('terminee_at')->nullable();

            $table->index(['statut', 'created_at']);
        });

        // Le chauffeur_id de « courses » etait obligatoire : impossible
        // d'enregistrer une course en cours de recherche de chauffeur.
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('chauffeur_id')->nullable()->change();
        });

        // Derniere position connue de chaque chauffeur. Le suivi temps reel
        // passe par Redis ; cette table sert d'historique et de repli.
        Schema::create('positions_chauffeurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chauffeur_id')->constrained('chauffeurs')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('cap')->nullable();
            $table->decimal('vitesse_kmh', 6, 2)->nullable();
            $table->timestamp('releve_at');
            $table->timestamps();

            $table->index(['chauffeur_id', 'releve_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions_chauffeurs');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'depart_latitude', 'depart_longitude', 'arrivee_latitude', 'arrivee_longitude',
                'type_vehicule', 'distance_km', 'duree_estimee_min', 'tarif_estime',
                'tarif_final', 'annulee_par', 'motif_annulation',
                'acceptee_at', 'prise_en_charge_at', 'terminee_at',
            ]);
        });

        Schema::dropIfExists('tarifs');
    }
};
