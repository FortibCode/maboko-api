<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On vérifie si la table s'appelle 'verification_identites' ou 'confiance'
        $tableName = Schema::hasTable('verification_identites') ? 'verification_identites' : 'confiance';

        if (Schema::hasTable($tableName)) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // On ajoute les colonnes manquantes si elles n'existent pas
                if (! Schema::hasColumn($tableName, 'numero_piece')) {
                    $table->string('numero_piece')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'photo_recto')) {
                    $table->longText('photo_recto')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'photo_verso')) {
                    $table->longText('photo_verso')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // On ne supprime rien pour éviter de casser la base
    }
};
