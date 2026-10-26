<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moyens de paiement enregistres (§5.1.10).
 *
 * Le Mobile Money ne se prete pas au stockage d'un instrument de paiement :
 * l'operateur envoie une invite au telephone et l'utilisateur confirme avec
 * son code secret. On ne conserve donc que l'operateur et le numero — de quoi
 * eviter de le retaper. Rien ici ne permet de declencher un paiement seul :
 * aucun jeton, aucun code, aucun numero de carte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moyens_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('users')->onDelete('cascade');
            $table->string('operateur', 20);
            $table->string('telephone', 20);
            $table->string('libelle', 60)->nullable();
            $table->boolean('par_defaut')->default(false);
            $table->timestamps();

            // Un meme numero ne doit pas etre enregistre deux fois chez le
            // meme operateur pour un meme compte.
            $table->unique(['utilisateur_id', 'operateur', 'telephone'], 'moyen_unique_par_compte');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moyens_paiement');
    }
};
