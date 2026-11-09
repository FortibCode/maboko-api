<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Prix proposé par le client
            if (! Schema::hasColumn('courses', 'prix_client')) {
                $table->decimal('prix_client', 10, 2)->nullable();
            }

            // Prix proposé par le chauffeur (contre-proposition)
            if (! Schema::hasColumn('courses', 'prix_chauffeur')) {
                $table->decimal('prix_chauffeur', 10, 2)->nullable();
            }

            // Statut de la course (en_attente, negociation, acceptee, en_cours, terminee, annulee)
            if (! Schema::hasColumn('courses', 'statut')) {
                $table->string('statut')->default('en_attente');
            }

            // Coordonnées GPS de départ et d'arrivée
            if (! Schema::hasColumn('courses', 'depart_lat')) {
                $table->decimal('depart_lat', 10, 7)->nullable();
                $table->decimal('depart_lng', 10, 7)->nullable();
                $table->decimal('arrivee_lat', 10, 7)->nullable();
                $table->decimal('arrivee_lng', 10, 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'prix_client',
                'prix_chauffeur',
                'statut',
                'depart_lat',
                'depart_lng',
                'arrivee_lat',
                'arrivee_lng',
            ]);
        });
    }
};

