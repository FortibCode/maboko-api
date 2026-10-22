<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publicités affichées au lancement de l'application mobile (§5.1.7).
     *
     * Une publicité met en avant un artisan du musée du savoir-faire. Elle
     * n'apparaît qu'une fois par jour et par utilisateur, pendant cinq
     * secondes. L'administrateur contrôle qui est mis en avant et quand.
     *
     * Aucune ligne ne peut avoir deux publicités actives en même temps :
     * l'API ne renvoie que la plus récente.
     */
    public function up(): void
    {
        Schema::create('publicites', function (Blueprint $table) {
            $table->id();

            // Artisan mis en avant. La suppression de son compte retire
            // la publicité associée : on ne peut pas vanter un compte
            // qui n'existe plus.
            $table->foreignId('artisan_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Qui a créé la publicité (pour l'audit).
            $table->foreignId('cree_par')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('titre', 150);
            $table->text('description')->nullable();

            // Photo de la réalisation mise en avant. Optionnelle : si elle
            // manque, on utilise l'avatar de l'artisan.
            $table->string('image_url', 500)->nullable();

            // Bornes de diffusion. Une publicité en dehors de sa fenêtre
            // n'est jamais servie, même si `actif` est à vrai.
            $table->timestamp('date_debut')->nullable();
            $table->timestamp('date_fin')->nullable();

            // Interrupteur manuel : l'admin peut désactiver avant la date
            // de fin sans supprimer la ligne.
            $table->boolean('actif')->default(true);

            // Statistiques simples : combien de fois la publicité a été
            // servie et cliquée. Utiles pour évaluer l'impact.
            $table->integer('nb_affichages')->default(0);
            $table->integer('nb_clics')->default(0);

            $table->timestamps();

            // Index pour la requête de la pub active.
            $table->index(['actif', 'date_debut', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicites');
    }
};