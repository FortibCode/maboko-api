<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chauffeurs', function (Blueprint $table) {
            $table->id();
            // Correspond à 'utilisateur_id' dans le factory et est lié à la table users
            $table->foreignId('utilisateur_id')->constrained('users')->onDelete('cascade');
            $table->string('permis_conduire')->unique();
            $table->string('vehicule_modele');
            $table->string('plaque_immatriculation')->unique();
            $table->boolean('disponibilite')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chauffeurs');
    }
};
