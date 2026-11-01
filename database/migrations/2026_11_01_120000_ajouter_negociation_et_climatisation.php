<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Négociation du prix d'une course (§5.1.9) et véhicule climatisé.
 *
 * Le client peut proposer son prix, un chauffeur peut en proposer un autre ;
 * chauffeur_propose_id retient qui a fait l'offre, pour lui attribuer la
 * course si le client l'accepte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->decimal('prix_client', 10, 2)->nullable()->after('tarif_final');
            $table->decimal('prix_chauffeur', 10, 2)->nullable()->after('prix_client');
            $table->foreignId('chauffeur_propose_id')
                ->nullable()
                ->after('chauffeur_id')
                ->constrained('chauffeurs')
                ->nullOnDelete();
        });

        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->boolean('est_climatise')->default(false)->after('vehicule_modele');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chauffeur_propose_id');
            $table->dropColumn(['prix_client', 'prix_chauffeur']);
        });

        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->dropColumn('est_climatise');
        });
    }
};
