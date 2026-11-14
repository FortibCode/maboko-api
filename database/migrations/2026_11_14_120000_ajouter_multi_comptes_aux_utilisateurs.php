<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-comptes artisan, sur le modèle d'Instagram.
 *
 * Une personne garde un seul compte principal (téléphone, email, mot de
 * passe) et peut y rattacher des profils, chacun étant un compte complet :
 * nom, publications, abonnés, messages. Les profils reprennent le téléphone
 * et l'email du compte principal, qui ne restent donc uniques qu'entre comptes
 * principaux : la connexion et la récupération du mot de passe ne visent
 * jamais un profil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('compte_principal_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->dropUnique(['email']);
            $table->dropUnique(['telephone']);
        });

        // Index uniques partiels : PostgreSQL et SQLite les acceptent, le
        // schéma de Blueprint ne sait pas les décrire.
        DB::statement('CREATE UNIQUE INDEX users_email_principal_unique ON users (email) WHERE compte_principal_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_telephone_principal_unique ON users (telephone) WHERE compte_principal_id IS NULL');
    }

    public function down(): void
    {
        // Les profils partagent téléphone et email : ils doivent disparaître
        // avant que l'unicité stricte puisse revenir.
        DB::table('users')->whereNotNull('compte_principal_id')->delete();

        DB::statement('DROP INDEX users_email_principal_unique');
        DB::statement('DROP INDEX users_telephone_principal_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compte_principal_id');
            $table->unique('email');
            $table->unique('telephone');
        });
    }
};
