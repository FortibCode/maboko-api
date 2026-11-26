<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On vérifie que la table n'existe pas déjà
        if (! Schema::hasTable('positions_chauffeurs')) {
            Schema::create('positions_chauffeurs', function (Blueprint $table) {
                $table->id();

                // Lien avec la table chauffeurs
                $table->foreignId('chauffeur_id')->constrained('chauffeurs')->cascadeOnDelete();

                // Coordonnées GPS
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);

                // Type de point (depart, arrivee, en_route) - optionnel pour le suivi
                $table->string('type')->default('en_route');

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('positions_chauffeurs');
    }
};
