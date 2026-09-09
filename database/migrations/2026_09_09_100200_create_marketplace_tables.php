<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coeur de la marketplace (§4.1, §5.1.7).
 *
 * Remplace les deux modelisations concurrentes de la demande de devis qui
 * coexistaient sans se connaitre : « missions » (rattachee a l'artisan, sans
 * client) et « bookings » (rattachee a deux utilisateurs, sans photo ni
 * adresse). Les deux anciennes tables sont conservees le temps de la reprise
 * de donnees puis supprimees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->foreignId('metier_id')->nullable()->constrained('metiers')->nullOnDelete();

            $table->string('titre');
            $table->text('description');

            $table->string('adresse');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('budget_estime', 12, 2)->nullable();
            $table->decimal('montant_propose', 12, 2)->nullable();
            $table->decimal('montant_final', 12, 2)->nullable();
            $table->date('date_souhaitee')->nullable();

            // en_attente, acceptee, refusee, en_cours, terminee, annulee
            $table->string('statut')->default('en_attente');
            $table->text('motif_refus')->nullable();

            $table->timestamp('acceptee_at')->nullable();
            $table->timestamp('terminee_at')->nullable();
            $table->timestamps();

            $table->index(['artisan_id', 'statut']);
            $table->index(['client_id', 'statut']);
        });

        Schema::create('demande_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_devis_id')->constrained('demandes_devis')->cascadeOnDelete();
            $table->string('url');
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });

        // Avis clients : pilier de la confiance (§2.2), absent du modele initial.
        Schema::create('avis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->foreignId('demande_devis_id')->nullable()->constrained('demandes_devis')->nullOnDelete();

            $table->unsignedTinyInteger('note'); // 1 a 5
            $table->text('commentaire')->nullable();
            $table->string('statut_moderation')->default('publie'); // publie, signale, masque

            $table->timestamps();

            // Un client ne note qu'une fois une meme intervention.
            $table->unique(['auteur_id', 'demande_devis_id']);
            $table->index(['artisan_id', 'statut_moderation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avis');
        Schema::dropIfExists('demande_photos');
        Schema::dropIfExists('demandes_devis');
    }
};
