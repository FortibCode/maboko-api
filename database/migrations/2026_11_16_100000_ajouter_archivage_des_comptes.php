<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On supprime la contrainte d'unicité sur l'email si elle existe encore
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');

        // On supprime la contrainte d'unicité sur le téléphone si elle existe encore
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_telephone_unique');

        // Ajout de la colonne compte_principal_id si elle n'existe pas
        if (! Schema::hasColumn('users', 'compte_principal_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('compte_principal_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        // Ajout de la colonne archive_at si elle n'existe pas
        if (! Schema::hasColumn('users', 'archive_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('archive_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // On ne remet pas les contraintes d'unicité (elles ont été supprimées volontairement)
        // On supprime juste les colonnes ajoutées
        if (Schema::hasColumn('users', 'compte_principal_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['compte_principal_id']);
                $table->dropColumn('compte_principal_id');
            });
        }

        if (Schema::hasColumn('users', 'archive_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('archive_at');
            });
        }
    }
};
