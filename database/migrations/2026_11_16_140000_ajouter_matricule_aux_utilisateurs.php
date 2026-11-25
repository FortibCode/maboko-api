<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matricule Maboko, affiché sur la carte du profil : MBK-ART-00042.
 *
 * Les comptes existants reçoivent le leur ici ; les suivants l'obtiennent à
 * leur création (User::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'matricule')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('matricule', 32)->nullable()->after('id');
            });
        }

        // Index unique créé uniquement s'il n'existe pas déjà (PostgreSQL).
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_matricule_unique ON users (matricule)');

        DB::table('users')
            ->select(['id', 'role'])
            ->whereNull('matricule')
            ->orderBy('id')
            ->chunkById(500, function ($comptes) {
                foreach ($comptes as $compte) {
                    DB::table('users')->where('id', $compte->id)->update([
                        'matricule' => User::matriculePour($compte->id, $compte->role),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_matricule_unique');

        if (Schema::hasColumn('users', 'matricule')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('matricule');
            });
        }
    }
};