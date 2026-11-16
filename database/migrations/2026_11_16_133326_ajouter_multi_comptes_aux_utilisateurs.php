<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. On supprime les anciennes contraintes strictes (IF EXISTS évite le crash)
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_telephone_unique');

        // 2. On ajoute la colonne pour le multi-compte si elle n'existe pas
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'compte_principal_id')) {
                $table->foreignId('compte_principal_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->cascadeOnDelete();
            }
        });

        // 3. On crée les index uniques partiels (l'idée de ton collègue)
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_principal_unique ON users (email) WHERE compte_principal_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_telephone_principal_unique ON users (telephone) WHERE compte_principal_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_principal_unique');
        DB::statement('DROP INDEX IF EXISTS users_telephone_principal_unique');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'compte_principal_id')) {
                $table->dropForeign(['compte_principal_id']);
                $table->dropColumn('compte_principal_id');
            }
        });
    }
};