<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche artisan ne portait que specialite, adresse et coordonnees.
 * Il lui manquait tout ce qui fait la confiance et le classement : note
 * moyenne, volume de missions, zone d'intervention, plan d'abonnement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisans', function (Blueprint $table) {
            $table->text('bio')->nullable();
            $table->string('zone_intervention')->nullable();
            $table->unsignedSmallInteger('rayon_km')->default(10);

            $table->decimal('note_moyenne', 3, 2)->default(0);
            $table->unsignedInteger('nb_avis')->default(0);
            $table->unsignedInteger('nb_missions_terminees')->default(0);

            // Position dans les resultats de recherche (§4.5) : recalcule a
            // partir des badges, du plan d'abonnement et de la note.
            $table->decimal('score_classement', 8, 2)->default(0);

            $table->string('statut_validation')->default('en_attente'); // en_attente, valide, rejete
            $table->boolean('local_professionnel')->default(false);
            $table->timestamp('valide_at')->nullable();

            $table->index('statut_validation');
            $table->index('score_classement');
        });

        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->string('type_vehicule')->default('voiture'); // moto, voiture
            $table->decimal('note_moyenne', 3, 2)->default(0);
            $table->unsignedInteger('nb_courses_terminees')->default(0);
            $table->string('statut_validation')->default('en_attente');
            $table->boolean('en_ligne')->default(false);
            $table->timestamp('valide_at')->nullable();

            $table->index(['en_ligne', 'disponibilite']);
            $table->index('statut_validation');
        });
    }

    public function down(): void
    {
        Schema::table('artisans', function (Blueprint $table) {
            $table->dropColumn([
                'bio', 'zone_intervention', 'rayon_km', 'note_moyenne', 'nb_avis',
                'nb_missions_terminees', 'score_classement', 'statut_validation',
                'local_professionnel', 'valide_at',
            ]);
        });

        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->dropColumn([
                'type_vehicule', 'note_moyenne', 'nb_courses_terminees',
                'statut_validation', 'en_ligne', 'valide_at',
            ]);
        });
    }
};
