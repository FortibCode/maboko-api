<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'prix_client')) {
                $table->decimal('prix_client', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('courses', 'prix_chauffeur')) {
                $table->decimal('prix_chauffeur', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('courses', 'chauffeur_propose_id')) {
                $table->foreignId('chauffeur_propose_id')->nullable()->constrained('chauffeurs')->nullOnDelete();
            }
            if (!Schema::hasColumn('courses', 'statut')) {
                $table->string('statut')->default('en_attente');
            }
            if (!Schema::hasColumn('courses', 'depart_lat')) {
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
                'prix_client', 'prix_chauffeur', 'chauffeur_propose_id',
                'statut', 'depart_lat', 'depart_lng', 'arrivee_lat', 'arrivee_lng'
            ]);
        });
    }
};